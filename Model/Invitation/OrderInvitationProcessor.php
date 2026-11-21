<?php

namespace Kiyoh\Reviews\Model\Invitation;

use Kiyoh\Reviews\Api\ApiServiceInterface;
use Kiyoh\Reviews\Helper\LogPrivacy;
use Kiyoh\Reviews\Helper\ProductCode;
use Kiyoh\Reviews\Model\Health\HealthReporter;
use Kiyoh\Reviews\Model\ResourceModel\OrderInviteRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class OrderInvitationProcessor
{
    private const CONFIG_PATH_INVITATION_TYPE = 'kiyoh_reviews/review_invitations/invitation_type';
    private const CONFIG_PATH_PRODUCT_SYNC_ENABLED = 'kiyoh_reviews/product_sync/enabled';
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
     * @var ProductCode
     */
    private $productCodeHelper;

    /**
     * @var OrderInviteRepository
     */
    private $inviteRepository;

    /**
     * @var string
     */
    private $lastFailureMessage = 'Invitation failed';
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var HealthReporter
     */
    private $healthReporter;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ApiServiceInterface $apiService,
        ProductCode $productCodeHelper,
        LoggerInterface $logger,
        HealthReporter $healthReporter,
        OrderInviteRepository $inviteRepository
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->apiService = $apiService;
        $this->productCodeHelper = $productCodeHelper;
        $this->logger = $logger;
        $this->healthReporter = $healthReporter;
        $this->inviteRepository = $inviteRepository;
    }

    public function sendInvitations(OrderInterface $order, int $storeId, int $attemptNumber): bool
    {
        $orderId = (int) $order->getId();
        $this->lastFailureMessage = 'Invitation failed';

        $invitationType = $this->getConfig(self::CONFIG_PATH_INVITATION_TYPE, $storeId);
        $productCodes = $this->extractProductCodesFromOrder($order, $storeId);

        $attempted = false;
        $success = false;

        $this->healthReporter->deferPublishing();
        try {
            if ($invitationType === 'product_only') {
                if ($productCodes !== []) {
                    $attempted = true;
                    $success = $this->sendProductInvitationWithRetry($order, $productCodes, $storeId);
                } else {
                    $this->logger->info('Kiyoh Reviews: No products found for product-only invitation', [
                        'order_id' => $orderId,
                    ]);
                }
            } elseif ($invitationType === 'shop_only') {
                $attempted = true;
                $success = $this->sendShopInvitation($order);
            } else {
                $attempted = true;
                $success = $this->sendCombinedInvitationWithRetry($order, $productCodes, $storeId);
            }
        } catch (\Exception $e) {
            $this->lastFailureMessage = $e->getMessage();
            $this->logger->error('Kiyoh Reviews: Failed to process order invitations', [
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
            ]);
            $success = false;
            $attempted = true;
        } finally {
            $this->healthReporter->flushDeferred();
        }

        if (!$attempted) {
            return false;
        }

        if ($success) {
            $this->inviteRepository->markSent($orderId, $storeId);
        } else {
            $this->inviteRepository->markFailed(
                $orderId,
                $storeId,
                $this->lastFailureMessage,
                $attemptNumber,
                InviteRetrySchedule::nextRetryTimestamp($attemptNumber)
            );
        }

        return $success;
    }

    private function sendCombinedInvitationWithRetry(OrderInterface $order, array $productCodes, int $storeId): bool
    {
        if (empty($productCodes)) {
            $this->logger->info('Kiyoh Reviews: Sending shop-only invitation (no valid products)', [
                'order_id' => $order->getId()
            ]);
            $success = $this->apiService->sendShopInvitation($order);

            if ($success) {
                $this->logger->info('Kiyoh Reviews: Shop invitation sent successfully', [
                    'order_id' => $order->getId(),
                    'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
                ]);
            } else {
                $this->lastFailureMessage = 'Shop invitation failed';
                $this->logger->error('Kiyoh Reviews: Shop invitation failed', [
                    'order_id' => $order->getId(),
                    'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
                ]);
            }

            return $success;
        }

        $this->syncOrderProductsBeforeInvitation($order, $storeId, $productCodes);

        $this->logger->info('Kiyoh Reviews: Attempting combined shop and product invitation', [
            'order_id' => $order->getId(),
            'product_count' => count($productCodes)
        ]);

        // First attempt with detailed error information
        $result = $this->apiService->sendProductInvitationWithDetails($order, $productCodes);

        if ($result['success']) {
            $this->logger->info('Kiyoh Reviews: Combined invitation sent successfully', [
                'order_id' => $order->getId(),
                'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
            ]);

            return true;
        }

        $errorCode = $result['error_code'];
        $this->lastFailureMessage = (string) ($result['message'] ?? 'Combined invitation failed');

        $this->logger->info('Kiyoh Reviews: Combined invitation failed', [
            'order_id' => $order->getId(),
            'error_code' => $errorCode,
            'error_message' => $result['message'],
        ]);

        if ($this->shouldSyncProductsForError($errorCode)) {
            $this->logger->info('Kiyoh Reviews: Error indicates missing products, syncing and retrying', [
                'order_id' => $order->getId(),
                'error_code' => $errorCode,
            ]);

            $this->syncOrderProducts($order, $storeId);

            $this->logger->info('Kiyoh Reviews: Retrying combined invitation after product sync', [
                'order_id' => $order->getId(),
            ]);

            $retrySuccess = $this->apiService->sendProductInvitation($order, $productCodes);

            if ($retrySuccess) {
                $this->logger->info('Kiyoh Reviews: Combined invitation retry successful', [
                    'order_id' => $order->getId(),
                    'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
                ]);

                return true;
            }

            $this->lastFailureMessage = 'Combined invitation retry failed';
            $this->logger->error('Kiyoh Reviews: Combined invitation retry failed', [
                'order_id' => $order->getId(),
                'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
            ]);

            return false;
        }

        $this->logger->info('Kiyoh Reviews: Error does not require product sync, skipping retry', [
            'order_id' => $order->getId(),
            'error_code' => $errorCode,
            'reason' => 'Error not related to missing products',
        ]);

        return false;
    }

    private function sendShopInvitation(OrderInterface $order): bool
    {
        $this->logger->info('Kiyoh Reviews: Sending shop invitation', [
            'order_id' => $order->getId()
        ]);

        $success = $this->apiService->sendShopInvitation($order);

        if ($success) {
            $this->logger->info('Kiyoh Reviews: Shop invitation sent successfully', [
                'order_id' => $order->getId(),
                'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
            ]);
        } else {
            $this->lastFailureMessage = 'Shop invitation failed';
            $this->logger->error('Kiyoh Reviews: Shop invitation failed', [
                'order_id' => $order->getId(),
                'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
            ]);
        }

        return $success;
    }

    private function sendProductInvitationWithRetry(OrderInterface $order, array $productCodes, int $storeId): bool
    {
        $this->syncOrderProductsBeforeInvitation($order, $storeId, $productCodes);

        $this->logger->info('Kiyoh Reviews: Attempting product invitation', [
            'order_id' => $order->getId(),
            'product_count' => count($productCodes)
        ]);

        // First attempt with detailed error information
        $result = $this->apiService->sendProductInvitationWithDetails($order, $productCodes);

        if ($result['success']) {
            $this->logger->info('Kiyoh Reviews: Product invitation sent successfully', [
                'order_id' => $order->getId(),
                'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
                'product_codes' => $productCodes,
            ]);

            return true;
        }

        $errorCode = $result['error_code'];
        $this->lastFailureMessage = (string) ($result['message'] ?? 'Product invitation failed');

        $this->logger->info('Kiyoh Reviews: Product invitation failed', [
            'order_id' => $order->getId(),
            'product_codes' => $productCodes,
            'error_code' => $errorCode,
            'error_message' => $result['message'],
        ]);

        if ($this->shouldSyncProductsForError($errorCode)) {
            $this->logger->info('Kiyoh Reviews: Error indicates missing products, syncing and retrying', [
                'order_id' => $order->getId(),
                'error_code' => $errorCode,
            ]);

            $this->syncOrderProducts($order, $storeId);

            $this->logger->info('Kiyoh Reviews: Retrying product invitation after product sync', [
                'order_id' => $order->getId(),
            ]);

            $retrySuccess = $this->apiService->sendProductInvitation($order, $productCodes);

            if ($retrySuccess) {
                $this->logger->info('Kiyoh Reviews: Product invitation retry successful', [
                    'order_id' => $order->getId(),
                    'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
                    'product_codes' => $productCodes,
                ]);

                return true;
            }

            $this->lastFailureMessage = 'Product invitation retry failed';
            $this->logger->error('Kiyoh Reviews: Product invitation retry failed', [
                'order_id' => $order->getId(),
                'email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
                'product_codes' => $productCodes,
            ]);

            return false;
        }

        $this->logger->info('Kiyoh Reviews: Error does not require product sync, skipping retry', [
            'order_id' => $order->getId(),
            'error_code' => $errorCode,
            'reason' => 'Error not related to missing products',
        ]);

        return false;
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

                    // Configurable → child SKU; custom options that append SKUs → catalog/simple SKU
                    $productCode = $this->productCodeHelper->resolveFromOrderItem($item, $product);
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
                            'name' => $item->getName() ?: $product->getName() ?: '',
                            'price' => (float) $item->getPrice(),
                            'cart_position' => count($validProducts), // Original cart order
                            'item_id' => $item->getId(),
                            'product_type' => $item->getProductType()
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
                'excluded_product_groups' => $excludedProductGroups,
                'detailed_products' => array_map(function($p) {
                    return [
                        'sku' => $p['sku'],
                        'name' => $p['name'],
                        'item_id' => $p['item_id'],
                        'product_type' => $p['product_type']
                    ];
                }, $validProducts)
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
            $excludedProductGroups = $this->getExcludedProductGroups($storeId);

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

                    // Skip excluded product groups
                    if ($this->isProductGroupExcluded($product, $excludedProductGroups)) {
                        $this->logger->debug('Kiyoh Reviews: Product excluded by attribute set during sync', [
                            'order_id' => $order->getId(),
                            'product_sku' => $product->getSku(),
                            'attribute_set_id' => $product->getAttributeSetId()
                        ]);
                        continue;
                    }

                    // Configurable → child SKU; custom options that append SKUs → catalog/simple SKU
                    $actualSku = $this->productCodeHelper->resolveFromOrderItem($item, $product);
                    
                    $this->logger->debug('Kiyoh Reviews: Syncing product', [
                        'order_id' => $order->getId(),
                        'item_sku' => $item->getSku(),
                        'product_sku' => $product->getSku(),
                        'actual_sku' => $actualSku,
                        'product_name' => $product->getName(),
                        'item_name' => $item->getName()
                    ]);

                    $productForSync = clone $product;
                    if ($storeId > 0) {
                        $productForSync->setStoreId($storeId);
                    }
                    $productForSync->setSku($actualSku);
                    if ($actualSku !== $this->productCodeHelper->sanitize((string) $product->getSku())) {
                        $productForSync->setName($item->getName() ?: $product->getName());
                    }
                    $success = $this->apiService->syncProduct($productForSync);
                    
                    if ($success) {
                        $syncedCount++;
                        $this->logger->info('Kiyoh Reviews: Product synced successfully', [
                            'order_id' => $order->getId(),
                            'synced_sku' => $actualSku,
                            'original_product_sku' => $product->getSku()
                        ]);
                    } else {
                        $failedCount++;
                        $this->logger->warning('Kiyoh Reviews: Product sync failed', [
                            'order_id' => $order->getId(),
                            'synced_sku' => $actualSku,
                            'original_product_sku' => $product->getSku()
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

    private function syncOrderProductsBeforeInvitation(OrderInterface $order, int $storeId, array $productCodes): void
    {
        if (empty($productCodes)) {
            return;
        }

        if (!$this->isProductSyncEnabled($storeId)) {
            $this->logger->info('Kiyoh Reviews: Skipping order product sync before invitation', [
                'order_id' => $order->getId(),
                'reason' => 'Product sync disabled'
            ]);
            return;
        }

        $this->logger->info('Kiyoh Reviews: Syncing order products before invitation', [
            'order_id' => $order->getId(),
            'product_codes' => $productCodes
        ]);

        $this->syncOrderProducts($order, $storeId);
    }

    private function isProductSyncEnabled(int $storeId): bool
    {
        return (bool) $this->getConfig(self::CONFIG_PATH_PRODUCT_SYNC_ENABLED, $storeId);
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
