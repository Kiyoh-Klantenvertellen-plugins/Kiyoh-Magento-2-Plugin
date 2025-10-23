<?php

namespace Kiyoh\Reviews\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Kiyoh\Reviews\Api\ApiServiceInterface;
use Psr\Log\LoggerInterface;

class SalesOrderSaveAfter implements ObserverInterface
{
    private const CONFIG_PATH_INVITATIONS_ENABLED = 'kiyoh_reviews/review_invitations/enabled';
    private const CONFIG_PATH_INVITATION_TYPE = 'kiyoh_reviews/review_invitations/invitation_type';
    private const CONFIG_PATH_ORDER_STATUS_TRIGGER = 'kiyoh_reviews/review_invitations/order_status_trigger';
    private const CONFIG_PATH_EXCLUDE_CUSTOMER_GROUPS = 'kiyoh_reviews/review_invitations/exclude_customer_groups';
    private const CONFIG_PATH_EXCLUDE_PRODUCT_GROUPS = 'kiyoh_reviews/review_invitations/exclude_product_groups';
    private const CONFIG_PATH_MAX_PRODUCTS = 'kiyoh_reviews/review_invitations/max_products_per_invite';
    private const CONFIG_PATH_PRODUCT_SORT_ORDER = 'kiyoh_reviews/review_invitations/product_sort_order';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var ApiServiceInterface
     */
    private $apiService;
    
    /**
     * @var GroupRepositoryInterface
     */
    private $groupRepository;
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ApiServiceInterface $apiService,
        GroupRepositoryInterface $groupRepository,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->apiService = $apiService;
        $this->groupRepository = $groupRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        
        if (!$order instanceof OrderInterface) {
            $this->logger->debug('Kiyoh Reviews Observer: Event object is not an OrderInterface');
            return;
        }

        $storeId = $order->getStoreId();
        $orderId = $order->getId();
        $orderStatus = $order->getStatus();
        $customerEmail = $order->getCustomerEmail();

        $this->logger->info('Kiyoh Reviews Observer: Order save event triggered', [
            'order_id' => $orderId,
            'order_status' => $orderStatus,
            'customer_email' => $customerEmail,
            'store_id' => $storeId,
            'event_time' => date('Y-m-d H:i:s')
        ]);

        if (!$this->shouldProcessOrder($order, $storeId)) {
            $this->logger->info('Kiyoh Reviews Observer: Order processing skipped', [
                'order_id' => $orderId,
                'reason' => 'Failed shouldProcessOrder validation'
            ]);
            return;
        }

        $this->logger->info('Kiyoh Reviews Observer: Starting invitation processing', [
            'order_id' => $orderId,
            'customer_email' => $customerEmail
        ]);

        $this->processOrderInvitations($order, $storeId);
    }

    private function shouldProcessOrder(OrderInterface $order, int $storeId): bool
    {
        $orderId = $order->getId();
        
        if (!$this->isInvitationsEnabled($storeId)) {
            $this->logger->info('Kiyoh Reviews Observer: Review invitations disabled', [
                'order_id' => $orderId,
                'invitations_enabled' => $this->isInvitationsEnabled($storeId)
            ]);
            return false;
        }

        if (!$this->isOrderStatusTriggered($order, $storeId)) {
            $this->logger->info('Kiyoh Reviews Observer: Order status not in trigger list', [
                'order_id' => $orderId,
                'current_status' => $order->getStatus(),
                'trigger_statuses' => $this->getConfig(self::CONFIG_PATH_ORDER_STATUS_TRIGGER, $storeId)
            ]);
            return false;
        }

        if ($this->isCustomerGroupExcluded($order, $storeId)) {
            $this->logger->info('Kiyoh Reviews Observer: Customer group excluded', [
                'order_id' => $orderId,
                'customer_group_id' => $order->getCustomerGroupId(),
                'excluded_groups' => $this->getConfig(self::CONFIG_PATH_EXCLUDE_CUSTOMER_GROUPS, $storeId)
            ]);
            return false;
        }

        if (!$order->getCustomerEmail()) {
            $this->logger->warning('Kiyoh Reviews Observer: Order has no customer email', [
                'order_id' => $orderId
            ]);
            return false;
        }

        $this->logger->info('Kiyoh Reviews Observer: Order validation passed', [
            'order_id' => $orderId,
            'ready_for_processing' => true
        ]);

        return true;
    }

    private function processOrderInvitations(OrderInterface $order, int $storeId): void
    {
        $invitationType = $this->getConfig(self::CONFIG_PATH_INVITATION_TYPE, $storeId);
        $productCodes = $this->extractProductCodesFromOrder($order, $storeId);

        try {
            if ($invitationType === 'product_only') {
                if (!empty($productCodes)) {
                    $this->sendProductInvitationWithRetry($order, $productCodes, $storeId);
                } else {
                    $this->logger->info('Kiyoh Reviews: No products found for product-only invitation', [
                        'order_id' => $order->getId()
                    ]);
                }
            } elseif ($invitationType === 'shop_only') {
                $this->sendShopInvitation($order);
            } else {
                // Default to shop_and_product
                $this->sendCombinedInvitationWithRetry($order, $productCodes, $storeId);
            }
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Reviews: Failed to process order invitations', [
                'order_id' => $order->getId(),
                'exception' => $e->getMessage()
            ]);
        }
    }

    private function sendCombinedInvitationWithRetry(OrderInterface $order, array $productCodes, int $storeId): void
    {
        if (empty($productCodes)) {
            $this->logger->info('Kiyoh Reviews: Sending shop-only invitation (no valid products)', [
                'order_id' => $order->getId()
            ]);
            $success = $this->apiService->sendShopInvitation($order);
            
            if ($success) {
                $this->logger->info('Kiyoh Reviews: Shop invitation sent successfully', [
                    'order_id' => $order->getId(),
                    'email' => $order->getCustomerEmail()
                ]);
            } else {
                $this->logger->error('Kiyoh Reviews: Shop invitation failed', [
                    'order_id' => $order->getId(),
                    'email' => $order->getCustomerEmail()
                ]);
            }
            return;
        }

        $this->logger->info('Kiyoh Reviews: Attempting combined shop and product invitation', [
            'order_id' => $order->getId(),
            'product_count' => count($productCodes)
        ]);

        // First attempt with detailed error information
        $result = $this->apiService->sendProductInvitationWithDetails($order, $productCodes);

        if ($result['success']) {
            $this->logger->info('Kiyoh Reviews: Combined invitation sent successfully', [
                'order_id' => $order->getId(),
                'email' => $order->getCustomerEmail()
            ]);
        } else {
            $errorCode = $result['error_code'];
            
            $this->logger->info('Kiyoh Reviews: Combined invitation failed', [
                'order_id' => $order->getId(),
                'error_code' => $errorCode,
                'error_message' => $result['message']
            ]);

            // Only sync products if the error is related to missing/invalid products
            if ($this->shouldSyncProductsForError($errorCode)) {
                $this->logger->info('Kiyoh Reviews: Error indicates missing products, syncing and retrying', [
                    'order_id' => $order->getId(),
                    'error_code' => $errorCode
                ]);

                // Sync products and retry
                $this->syncOrderProducts($order, $storeId);
                
                $this->logger->info('Kiyoh Reviews: Retrying combined invitation after product sync', [
                    'order_id' => $order->getId()
                ]);

                $retrySuccess = $this->apiService->sendProductInvitation($order, $productCodes);

                if ($retrySuccess) {
                    $this->logger->info('Kiyoh Reviews: Combined invitation retry successful', [
                        'order_id' => $order->getId(),
                        'email' => $order->getCustomerEmail()
                    ]);
                } else {
                    $this->logger->error('Kiyoh Reviews: Combined invitation retry failed', [
                        'order_id' => $order->getId(),
                        'email' => $order->getCustomerEmail()
                    ]);
                }
            } else {
                $this->logger->info('Kiyoh Reviews: Error does not require product sync, skipping retry', [
                    'order_id' => $order->getId(),
                    'error_code' => $errorCode,
                    'reason' => 'Error not related to missing products'
                ]);
            }
        }
    }

    private function sendShopInvitation(OrderInterface $order): void
    {
        $this->logger->info('Kiyoh Reviews: Sending shop invitation', [
            'order_id' => $order->getId()
        ]);

        $success = $this->apiService->sendShopInvitation($order);

        if ($success) {
            $this->logger->info('Kiyoh Reviews: Shop invitation sent successfully', [
                'order_id' => $order->getId(),
                'email' => $order->getCustomerEmail()
            ]);
        } else {
            $this->logger->error('Kiyoh Reviews: Shop invitation failed', [
                'order_id' => $order->getId(),
                'email' => $order->getCustomerEmail()
            ]);
        }
    }

    private function sendProductInvitationWithRetry(OrderInterface $order, array $productCodes, int $storeId): void
    {
        $this->logger->info('Kiyoh Reviews: Attempting product invitation', [
            'order_id' => $order->getId(),
            'product_count' => count($productCodes)
        ]);

        // First attempt with detailed error information
        $result = $this->apiService->sendProductInvitationWithDetails($order, $productCodes);

        if ($result['success']) {
            $this->logger->info('Kiyoh Reviews: Product invitation sent successfully', [
                'order_id' => $order->getId(),
                'email' => $order->getCustomerEmail(),
                'product_codes' => $productCodes
            ]);
        } else {
            $errorCode = $result['error_code'];
            
            $this->logger->info('Kiyoh Reviews: Product invitation failed', [
                'order_id' => $order->getId(),
                'product_codes' => $productCodes,
                'error_code' => $errorCode,
                'error_message' => $result['message']
            ]);

            // Only sync products if the error is related to missing/invalid products
            if ($this->shouldSyncProductsForError($errorCode)) {
                $this->logger->info('Kiyoh Reviews: Error indicates missing products, syncing and retrying', [
                    'order_id' => $order->getId(),
                    'error_code' => $errorCode
                ]);

                // Sync products and retry
                $this->syncOrderProducts($order, $storeId);
                
                $this->logger->info('Kiyoh Reviews: Retrying product invitation after product sync', [
                    'order_id' => $order->getId()
                ]);

                $retrySuccess = $this->apiService->sendProductInvitation($order, $productCodes);

                if ($retrySuccess) {
                    $this->logger->info('Kiyoh Reviews: Product invitation retry successful', [
                        'order_id' => $order->getId(),
                        'email' => $order->getCustomerEmail(),
                        'product_codes' => $productCodes
                    ]);
                } else {
                    $this->logger->error('Kiyoh Reviews: Product invitation retry failed', [
                        'order_id' => $order->getId(),
                        'email' => $order->getCustomerEmail(),
                        'product_codes' => $productCodes
                    ]);
                }
            } else {
                $this->logger->info('Kiyoh Reviews: Error does not require product sync, skipping retry', [
                    'order_id' => $order->getId(),
                    'error_code' => $errorCode,
                    'reason' => 'Error not related to missing products'
                ]);
            }
        }
    }

    private function extractProductCodesFromOrder(OrderInterface $order, int $storeId): array
    {
        try {
            $maxProducts = (int) $this->getConfig(self::CONFIG_PATH_MAX_PRODUCTS, $storeId) ?: 10;
            $sortOrder = $this->getConfig(self::CONFIG_PATH_PRODUCT_SORT_ORDER, $storeId) ?: 'cart_order';
            $excludedProductGroups = $this->getExcludedProductGroups($storeId);

            $validProducts = [];
            $totalItems = 0;
            $excludedItems = 0;

            // First pass: collect all valid products with their data
            foreach ($order->getAllVisibleItems() as $item) {
                try {
                    $totalItems++;
                    $product = $item->getProduct();
                    if (!$product) {
                        continue;
                    }

                    if ($this->isProductGroupExcluded($product, $excludedProductGroups)) {
                        $excludedItems++;
                        $this->logger->debug('Kiyoh Reviews: Product excluded by attribute set', [
                            'order_id' => $order->getId(),
                            'product_sku' => $product->getSku(),
                            'attribute_set_id' => $product->getAttributeSetId()
                        ]);
                        continue;
                    }

                    $productCode = $product->getSku();
                    if (!$productCode) {
                        continue;
                    }

                    // Check for duplicates
                    $isDuplicate = false;
                    foreach ($validProducts as $validProduct) {
                        if ($validProduct['sku'] === $productCode) {
                            $isDuplicate = true;
                            break;
                        }
                    }

                    if (!$isDuplicate) {
                        $validProducts[] = [
                            'sku' => $productCode,
                            'name' => $product->getName() ?: '',
                            'price' => (float) $item->getPrice(),
                            'cart_position' => count($validProducts) // Original cart order
                        ];
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Kiyoh Reviews: Error processing order item', [
                        'order_id' => $order->getId(),
                        'item_id' => $item->getId(),
                        'exception' => $e->getMessage()
                    ]);
                }
            }

            // Sort products based on configuration
            $this->sortProducts($validProducts, $sortOrder);

            // Extract product codes up to the maximum limit
            $productCodes = [];
            for ($i = 0; $i < min(count($validProducts), $maxProducts); $i++) {
                $productCodes[] = $validProducts[$i]['sku'];
            }

            $this->logger->debug('Kiyoh Reviews: Extracted and sorted product codes from order', [
                'order_id' => $order->getId(),
                'total_items' => $totalItems,
                'excluded_items' => $excludedItems,
                'valid_products_found' => count($validProducts),
                'extracted_codes' => count($productCodes),
                'max_products' => $maxProducts,
                'sort_order' => $sortOrder,
                'product_codes' => $productCodes,
                'excluded_product_groups' => $excludedProductGroups
            ]);

            return $productCodes;
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Reviews: Critical error extracting product codes', [
                'order_id' => $order->getId(),
                'exception' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function sortProducts(array &$products, string $sortOrder): void
    {
        switch ($sortOrder) {
            case 'price_desc':
                usort($products, function ($a, $b) {
                    return $b['price'] <=> $a['price'];
                });
                break;
            case 'price_asc':
                usort($products, function ($a, $b) {
                    return $a['price'] <=> $b['price'];
                });
                break;
            case 'name_asc':
                usort($products, function ($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
                break;
            case 'name_desc':
                usort($products, function ($a, $b) {
                    return strcasecmp($b['name'], $a['name']);
                });
                break;
            case 'sku_asc':
                usort($products, function ($a, $b) {
                    return strcasecmp($a['sku'], $b['sku']);
                });
                break;
            case 'sku_desc':
                usort($products, function ($a, $b) {
                    return strcasecmp($b['sku'], $a['sku']);
                });
                break;
            case 'cart_order':
            default:
                // Keep original cart order (already sorted by cart_position)
                usort($products, function ($a, $b) {
                    return $a['cart_position'] <=> $b['cart_position'];
                });
                break;
        }
    }

    private function syncOrderProducts(OrderInterface $order, int $storeId): void
    {
        try {
            $maxProducts = (int) $this->getConfig(self::CONFIG_PATH_MAX_PRODUCTS, $storeId) ?: 10;
            $syncedCount = 0;
            $failedCount = 0;

            $this->logger->info('Kiyoh Reviews: Starting product sync for order', [
                'order_id' => $order->getId(),
                'max_products' => $maxProducts
            ]);

            foreach ($order->getAllVisibleItems() as $item) {
                if ($syncedCount >= $maxProducts) {
                    break;
                }

                try {
                    $product = $item->getProduct();
                    if (!$product) {
                        $this->logger->debug('Kiyoh Reviews: No product for order item', [
                            'order_id' => $order->getId(),
                            'item_id' => $item->getId()
                        ]);
                        continue;
                    }

                    $this->logger->debug('Kiyoh Reviews: Syncing product', [
                        'order_id' => $order->getId(),
                        'product_sku' => $product->getSku(),
                        'product_name' => $product->getName()
                    ]);

                    $success = $this->apiService->syncProduct($product);
                    
                    if ($success) {
                        $syncedCount++;
                        $this->logger->info('Kiyoh Reviews: Product synced successfully', [
                            'order_id' => $order->getId(),
                            'product_sku' => $product->getSku()
                        ]);
                    } else {
                        $failedCount++;
                        $this->logger->warning('Kiyoh Reviews: Product sync failed', [
                            'order_id' => $order->getId(),
                            'product_sku' => $product->getSku()
                        ]);
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->logger->error('Kiyoh Reviews: Product sync exception', [
                        'order_id' => $order->getId(),
                        'item_id' => $item->getId(),
                        'exception' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('Kiyoh Reviews: Product sync completed', [
                'order_id' => $order->getId(),
                'synced_count' => $syncedCount,
                'failed_count' => $failedCount
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Reviews: Critical error during order product sync', [
                'order_id' => $order->getId(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function isOrderStatusTriggered(OrderInterface $order, int $storeId): bool
    {
        $triggerStatuses = $this->getConfig(self::CONFIG_PATH_ORDER_STATUS_TRIGGER, $storeId);
        
        if (!$triggerStatuses) {
            return false;
        }

        $triggerStatusArray = explode(',', $triggerStatuses);
        $currentStatus = $order->getStatus();

        $isTriggered = in_array($currentStatus, $triggerStatusArray);

        $this->logger->debug('Kiyoh Reviews: Order status trigger check', [
            'order_id' => $order->getId(),
            'current_status' => $currentStatus,
            'trigger_statuses' => $triggerStatusArray,
            'is_triggered' => $isTriggered
        ]);

        return $isTriggered;
    }

    private function isCustomerGroupExcluded(OrderInterface $order, int $storeId): bool
    {
        $excludedGroups = $this->getConfig(self::CONFIG_PATH_EXCLUDE_CUSTOMER_GROUPS, $storeId);
        
        if (!$excludedGroups) {
            return false;
        }

        $excludedGroupArray = explode(',', $excludedGroups);
        $customerGroupId = $order->getCustomerGroupId();

        $isExcluded = in_array($customerGroupId, $excludedGroupArray);

        $this->logger->debug('Kiyoh Reviews: Customer group exclusion check', [
            'order_id' => $order->getId(),
            'customer_group_id' => $customerGroupId,
            'excluded_groups' => $excludedGroupArray,
            'is_excluded' => $isExcluded
        ]);

        return $isExcluded;
    }

    private function isInvitationsEnabled(int $storeId): bool
    {
        return (bool) $this->getConfig(self::CONFIG_PATH_INVITATIONS_ENABLED, $storeId);
    }

    private function shouldSyncProductsForError(string $errorCode): bool
    {
        // Error codes that indicate missing or invalid products that could be fixed by syncing
        $productSyncErrors = [
            'INVALID_PRODUCT_ID',
            'PRODUCT_NOT_FOUND',
            'UNKNOWN_PRODUCT',
            'MISSING_PRODUCT',
            'PRODUCT_DOES_NOT_EXIST',
            'INVALID_PRODUCT_CODE',
            'PRODUCT_NOT_AVAILABLE'
        ];

        // Error codes that should NOT trigger product sync
        $nonProductErrors = [
            'INVITE_ALREADY_SENT',
            'DUPLICATE_INVITATION',
            'EMAIL_ALREADY_INVITED',
            'INVITATION_LIMIT_REACHED',
            'INVALID_EMAIL',
            'MISSING_EMAIL',
            'INVALID_LOCATION_ID',
            'MISSING_TOKEN',
            'INVALID_TOKEN',
            'CURL_ERROR',
            'INVALID_JSON',
            'EXCEPTION',
            'DISABLED'
        ];

        $shouldSync = in_array($errorCode, $productSyncErrors);
        
        $this->logger->debug('Kiyoh Reviews: Product sync decision', [
            'error_code' => $errorCode,
            'should_sync' => $shouldSync,
            'product_sync_errors' => $productSyncErrors,
            'non_product_errors' => $nonProductErrors
        ]);

        return $shouldSync;
    }

    private function getConfig(string $path, int $storeId)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function getExcludedProductGroups(int $storeId): array
    {
        $excludedGroups = $this->getConfig(self::CONFIG_PATH_EXCLUDE_PRODUCT_GROUPS, $storeId);
        
        if (!$excludedGroups) {
            return [];
        }

        return explode(',', $excludedGroups);
    }

    private function isProductGroupExcluded($product, array $excludedProductGroups): bool
    {
        if (empty($excludedProductGroups)) {
            return false;
        }

        $productAttributeSetId = (string) $product->getAttributeSetId();
        return in_array($productAttributeSetId, $excludedProductGroups);
    }
}