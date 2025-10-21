<?php

namespace Kiyoh\Reviews\Service;

use Kiyoh\Reviews\Api\ApiServiceInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Locale\Resolver as LocaleResolver;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class ApiService implements ApiServiceInterface
{
    private const CONFIG_PATH_ENABLED = 'kiyoh_reviews/api_settings/enabled';
    private const CONFIG_PATH_SERVER = 'kiyoh_reviews/api_settings/server';
    private const CONFIG_PATH_LOCATION_ID = 'kiyoh_reviews/api_settings/location_id';
    private const CONFIG_PATH_API_TOKEN = 'kiyoh_reviews/api_settings/api_token';
    private const CONFIG_PATH_INVITATIONS_ENABLED = 'kiyoh_reviews/review_invitations/enabled';
    private const CONFIG_PATH_INVITATION_TYPE = 'kiyoh_reviews/review_invitations/invitation_type';
    private const CONFIG_PATH_FALLBACK_LANGUAGE = 'kiyoh_reviews/review_invitations/fallback_language';
    private const CONFIG_PATH_DELAY_DAYS = 'kiyoh_reviews/review_invitations/delay_days';
    private const CONFIG_PATH_MAX_PRODUCTS = 'kiyoh_reviews/review_invitations/max_products_per_invite';

    private const TIMEOUT_INVITATION = 2;
    private const TIMEOUT_REVIEWS = 1;
    private const BULK_SYNC_MAX = 200;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var EncryptorInterface
     */
    private $encryptor;
    
    /**
     * @var LoggerInterface
     */
    private $logger;
    
    /**
     * @var LocaleResolver
     */
    private $localeResolver;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        LoggerInterface $logger,
        LocaleResolver $localeResolver
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->localeResolver = $localeResolver;
    }

    public function sendShopInvitation(OrderInterface $order): bool
    {
        $result = $this->sendShopInvitationWithDetails($order);
        return $result['success'];
    }

    public function sendProductInvitation(OrderInterface $order, array $productCodes): bool
    {
        $result = $this->sendProductInvitationWithDetails($order, $productCodes);
        return $result['success'];
    }

    public function sendShopInvitationWithDetails(OrderInterface $order): array
    {
        $storeId = $order->getStoreId();

        if (!$this->isEnabled($storeId) || !$this->isInvitationsEnabled($storeId)) {
            return ['success' => false, 'error_code' => 'DISABLED', 'message' => 'Review invitations disabled'];
        }

        $invitationData = $this->buildInvitationData($order, []);
        return $this->sendInvitationRequestWithDetails($invitationData, $storeId, false);
    }

    public function sendProductInvitationWithDetails(OrderInterface $order, array $productCodes): array
    {
        $storeId = $order->getStoreId();

        if (!$this->isEnabled($storeId) || !$this->isInvitationsEnabled($storeId)) {
            return ['success' => false, 'error_code' => 'DISABLED', 'message' => 'Review invitations disabled'];
        }

        $maxProducts = (int) $this->getConfig(self::CONFIG_PATH_MAX_PRODUCTS, $storeId) ?: 10;
        $limitedProductCodes = array_slice($productCodes, 0, $maxProducts);

        $invitationType = $this->getConfig(self::CONFIG_PATH_INVITATION_TYPE, $storeId);
        $productInviteFlag = ($invitationType === 'product_only'); // true = product only, false = shop + product

        $invitationData = $this->buildInvitationData($order, $limitedProductCodes);
        return $this->sendInvitationRequestWithDetails($invitationData, $storeId, $productInviteFlag);
    }

    public function sendShopAndProductInvitation(OrderInterface $order, array $productCodes): bool
    {
        $storeId = $order->getStoreId();

        if (!$this->isEnabled($storeId) || !$this->isInvitationsEnabled($storeId)) {
            return false;
        }

        $maxProducts = (int) $this->getConfig(self::CONFIG_PATH_MAX_PRODUCTS, $storeId) ?: 10;
        $limitedProductCodes = array_slice($productCodes, 0, $maxProducts);

        // For shop + product: product_invite = false, include product codes
        $invitationData = $this->buildInvitationData($order, $limitedProductCodes);
        return $this->sendInvitationRequest($invitationData, $storeId, false);
    }

    public function syncProduct(ProductInterface $product): bool
    {
        try {
            $storeId = $product->getStoreId() ?: 0;

            if (!$this->isEnabled($storeId)) {
                $this->logger->debug('Kiyoh API: Product sync skipped - API disabled', [
                    'product_sku' => $product->getSku()
                ]);
                return false;
            }

            $productData = $this->buildProductData($product);
            return $this->sendProductSyncRequest($productData, $storeId);
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh API: Product sync exception', [
                'product_sku' => $product->getSku() ?? 'unknown',
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function syncProductsBulk(array $products): array
    {
        if (empty($products)) {
            return ['success' => 0, 'failed' => 0, 'errors' => []];
        }

        try {
            $storeId = $products[0]->getStoreId() ?: 0;

            if (!$this->isEnabled($storeId)) {
                $this->logger->debug('Kiyoh API: Bulk product sync skipped - API disabled');
                return ['success' => 0, 'failed' => count($products), 'errors' => ['API disabled']];
            }

            $results = ['success' => 0, 'failed' => 0, 'errors' => []];
            $batches = array_chunk($products, self::BULK_SYNC_MAX);

            foreach ($batches as $batchIndex => $batch) {
                try {
                    // Add rate limiting: wait 3 seconds between batches (except for the first batch)
                    if ($batchIndex > 0) {
                        $this->logger->info('Kiyoh API: Rate limiting - waiting 3 seconds before next batch', [
                            'batch_index' => $batchIndex,
                            'total_batches' => count($batches)
                        ]);
                        sleep(3);
                    }

                    $batchData = [];
                    foreach ($batch as $product) {
                        try {
                            $batchData[] = $this->buildProductData($product);
                        } catch (\Exception $e) {
                            $this->logger->warning('Kiyoh API: Failed to build product data in batch', [
                                'product_sku' => $product->getSku() ?? 'unknown',
                                'batch_index' => $batchIndex,
                                'exception' => $e->getMessage()
                            ]);
                            $results['failed']++;
                            $results['errors'][] = 'Failed to build data for ' . ($product->getSku() ?? 'unknown');
                        }
                    }

                    if (!empty($batchData)) {
                        $batchResult = $this->sendBulkProductSyncRequest($batchData, $storeId);
                        $results['success'] += $batchResult['success'];
                        $results['failed'] += $batchResult['failed'];
                        $results['errors'] = array_merge($results['errors'], $batchResult['errors']);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Kiyoh API: Batch processing exception', [
                        'batch_index' => $batchIndex,
                        'batch_size' => count($batch),
                        'exception' => $e->getMessage()
                    ]);
                    $results['failed'] += count($batch);
                    $results['errors'][] = 'Batch ' . $batchIndex . ' failed: ' . $e->getMessage();
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh API: Bulk sync critical exception', [
                'total_products' => count($products),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => 0, 'failed' => count($products), 'errors' => ['Critical error: ' . $e->getMessage()]];
        }
    }

    public function getShopReviews(int $storeId): ?array
    {
        if (!$this->isEnabled($storeId)) {
            return null;
        }

        $server = $this->getServerUrl($storeId);
        $locationId = $this->getConfig(self::CONFIG_PATH_LOCATION_ID, $storeId);
        $apiToken = $this->getConfig(self::CONFIG_PATH_API_TOKEN, $storeId);

        if (!$locationId || !$apiToken) {
            $this->logger->error('Kiyoh API: Missing location ID or API token for shop reviews');
            return null;
        }

        $url = sprintf('%s/v1/publication/review/external/location/statistics?locationId=%s', $server, $locationId);

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_REVIEWS);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Publication-Api-Token: ' . $apiToken
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            if ($response === false) {
                $this->logger->error('Kiyoh API: Shop reviews request failed');
                return null;
            }

            $data = json_decode($response, true);

            if (isset($data['errorCode'])) {
                $this->logger->error('Kiyoh API: Shop reviews error', ['error' => $data]);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh API: Shop reviews exception', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    public function getProductReviews(string $productCode, int $storeId): ?array
    {
        if (!$this->isEnabled($storeId)) {
            return null;
        }

        $server = $this->getServerUrl($storeId);
        $locationId = $this->getConfig(self::CONFIG_PATH_LOCATION_ID, $storeId);
        $apiToken = $this->getConfig(self::CONFIG_PATH_API_TOKEN, $storeId);

        if (!$locationId || !$apiToken || !$productCode) {
            $this->logger->error('Kiyoh API: Missing required data for product reviews');
            return null;
        }

        $url = sprintf(
            '%s/v1/publication/product/review/external?locationId=%s&productCode=%s',
            $server,
            $locationId,
            urlencode($productCode)
        );

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_REVIEWS);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Publication-Api-Token: ' . $apiToken
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            if ($response === false) {
                $this->logger->error('Kiyoh API: Product reviews request failed', ['productCode' => $productCode]);
                return null;
            }

            $data = json_decode($response, true);

            if (isset($data['errorCode'])) {
                $this->logger->error('Kiyoh API: Product reviews error', ['error' => $data, 'productCode' => $productCode]);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh API: Product reviews exception', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function buildInvitationData(OrderInterface $order, array $productCodes): array
    {
        $storeId = $order->getStoreId();
        $locationId = $this->getConfig(self::CONFIG_PATH_LOCATION_ID, $storeId);
        $delayDays = $this->getConfig(self::CONFIG_PATH_DELAY_DAYS, $storeId);
        if ($delayDays === null || $delayDays === '') {
            $delayDays = 7; // Default fallback only if not configured
        } else {
            $delayDays = (int) $delayDays; // Convert to int, preserving 0
        }

        $language = $this->detectLanguageFromOrder($order, $storeId);

        $firstName = $order->getCustomerFirstname();
        $lastName = $order->getCustomerLastname();

        if (!$firstName && $order->getShippingAddress()) {
            $firstName = $order->getShippingAddress()->getFirstname();
        }
        if (!$lastName && $order->getShippingAddress()) {
            $lastName = $order->getShippingAddress()->getLastname();
        }

        $data = [
            'location_id' => $locationId,
            'invite_email' => $order->getCustomerEmail(),
            'delay' => $delayDays,
            'language' => $language,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'reference_code' => $order->getIncrementId() ?? 'test-order'
        ];

        if (!empty($productCodes)) {
            $data['product_code'] = $productCodes;
        }

        return $data;
    }

    private function buildProductData(ProductInterface $product): array
    {
        try {
            $storeId = $product->getStoreId() ?: 0;
            $locationId = $this->getConfig(self::CONFIG_PATH_LOCATION_ID, $storeId);

            $productCode = $product->getSku();
            $productName = $product->getName();
            
            if (!$productCode || !$productName) {
                throw new \InvalidArgumentException('Product must have SKU and name');
            }
            
            // Ensure we have a valid product URL
            $productUrl = '';
            try {
                $productUrl = $product->getProductUrl();
            } catch (\Exception $e) {
                $this->logger->debug('Kiyoh API: Could not get product URL', [
                    'product_sku' => $productCode,
                    'exception' => $e->getMessage()
                ]);
            }
            
            if (!$productUrl || !filter_var($productUrl, FILTER_VALIDATE_URL)) {
                $productUrl = 'https://example.com/product/' . urlencode(strtolower($productCode));
            }

            // Ensure we have a valid image URL
            $imageUrl = '';
            if ($product->getImage() && $product->getImage() !== 'no_selection') {
                try {
                    $imageUrl = $product->getMediaConfig()->getMediaUrl($product->getImage());
                } catch (\Exception $e) {
                    $this->logger->debug('Kiyoh API: Could not get product image URL', [
                        'product_sku' => $productCode,
                        'exception' => $e->getMessage()
                    ]);
                }
            }
            
            // Provide a fallback image URL if none available
            if (!$imageUrl || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imageUrl = 'https://via.placeholder.com/300x300.png?text=' . urlencode($productName);
            }

            // Build the basic required data
            $data = [
                'location_id' => (string) $locationId,
                'product_code' => (string) $productCode,
                'product_name' => (string) $productName,
                'source_url' => $productUrl,
                'image_url' => $imageUrl,
                'active' => true
            ];

            // Only add optional fields if they exist and are not empty
            $sku = $product->getSku();
            if ($sku && $sku !== $productCode) {
                $data['skus'] = [(string) $sku];
            }

            // Only add GTIN if it's a valid format (13 digits)
            try {
                $gtin = $product->getData('gtin');
                if ($gtin && preg_match('/^\d{13}$/', $gtin)) {
                    $data['gtins'] = [(string) $gtin];
                }
            } catch (\Exception $e) {
                // Attribute may not exist, skip silently
            }

            // Only add MPN if it exists and is not empty
            try {
                $mpn = $product->getData('mpn');
                if ($mpn && trim($mpn) !== '') {
                    $data['mpns'] = [(string) $mpn];
                }
            } catch (\Exception $e) {
                // Attribute may not exist, skip silently
            }

            // Only add brand/cluster if it exists
            try {
                $brand = $product->getData('brand');
                if ($brand && trim($brand) !== '') {
                    $data['cluster_code'] = (string) $brand;
                }
            } catch (\Exception $e) {
                // Attribute may not exist, skip silently
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh API: Failed to build product data', [
                'product_sku' => $product->getSku() ?? 'unknown',
                'exception' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function sendInvitationRequest(array $data, int $storeId, bool $productInvite): bool
    {
        $result = $this->sendInvitationRequestWithDetails($data, $storeId, $productInvite);
        return $result['success'];
    }

    private function sendInvitationRequestWithDetails(array $data, int $storeId, bool $productInvite): array
    {
        $server = $this->getServerUrl($storeId);
        $apiToken = $this->getConfig(self::CONFIG_PATH_API_TOKEN, $storeId);

        if (!$apiToken) {
            $this->logger->error('Kiyoh API: Missing API token for invitation', [
                'store_id' => $storeId,
                'email' => $data['invite_email'] ?? 'unknown'
            ]);
            return ['success' => false, 'error_code' => 'MISSING_TOKEN', 'message' => 'Missing API token'];
        }

        $data['product_invite'] = $productInvite;

        $url = sprintf('%s/v1/invite/external', $server);

        $this->logger->info('Kiyoh API: Sending invitation request', [
            'url' => $url,
            'email' => $data['invite_email'],
            'product_invite' => $productInvite,
            'product_codes_count' => isset($data['product_code']) ? count($data['product_code']) : 0,
            'delay_days' => $data['delay'] ?? 'not_set',
            'language' => $data['language'] ?? 'not_set',
            'full_request_data' => $data
        ]);

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_INVITATION);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Publication-Api-Token: ' . $apiToken,
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $this->logger->error('Kiyoh API: Invitation cURL failed', [
                    'email' => $data['invite_email'],
                    'curl_error' => $curlError,
                    'url' => $url
                ]);
                return ['success' => false, 'error_code' => 'CURL_ERROR', 'message' => $curlError];
            }

            $this->logger->info('Kiyoh API: Received invitation response', [
                'email' => $data['invite_email'],
                'http_code' => $httpCode,
                'response_body' => $response,
                'response_length' => strlen($response)
            ]);

            $responseData = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Kiyoh API: Invalid JSON response for invitation', [
                    'email' => $data['invite_email'],
                    'response_body' => $response,
                    'json_error' => json_last_error_msg(),
                    'http_code' => $httpCode
                ]);
                return ['success' => false, 'error_code' => 'INVALID_JSON', 'message' => json_last_error_msg()];
            }

            if (isset($responseData['errorCode'])) {
                $errorCode = $responseData['errorCode'];
                $errorMessage = $responseData['message'] ?? $errorCode;
                
                $this->logger->error('Kiyoh API: Invitation API error', [
                    'email' => $data['invite_email'],
                    'error_response' => $responseData,
                    'request_data' => $data,
                    'http_code' => $httpCode
                ]);
                
                return [
                    'success' => false, 
                    'error_code' => $errorCode, 
                    'message' => $errorMessage,
                    'response_data' => $responseData
                ];
            }

            $this->logger->info('Kiyoh API: Invitation sent successfully', [
                'email' => $data['invite_email'],
                'response_data' => $responseData,
                'http_code' => $httpCode
            ]);
            
            return [
                'success' => true, 
                'error_code' => null, 
                'message' => 'Invitation sent successfully',
                'response_data' => $responseData
            ];
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh API: Invitation exception', [
                'email' => $data['invite_email'],
                'exception' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
                'request_data' => $data
            ]);
            
            return ['success' => false, 'error_code' => 'EXCEPTION', 'message' => $e->getMessage()];
        }
    }

    private function sendProductSyncRequest(array $productData, int $storeId): bool
    {
        $server = $this->getServerUrl($storeId);
        $apiToken = $this->getConfig(self::CONFIG_PATH_API_TOKEN, $storeId);

        if (!$apiToken) {
            $this->logger->error('Kiyoh API: Missing API token for product sync', [
                'product' => $productData['product_code'] ?? 'unknown'
            ]);
            return false;
        }

        $url = sprintf('%s/v1/location/product/external', $server);

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Failed to initialize cURL');
            }
            
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_INVITATION);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($productData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Publication-Api-Token: ' . $apiToken,
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $this->logger->error('Kiyoh API: Product sync cURL failed', [
                    'product' => $productData['product_code'] ?? 'unknown',
                    'curl_error' => $curlError,
                    'url' => $url
                ]);
                return false;
            }

            $responseData = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Kiyoh API: Invalid JSON response for product sync', [
                    'product' => $productData['product_code'] ?? 'unknown',
                    'http_code' => $httpCode,
                    'response' => substr($response, 0, 500),
                    'json_error' => json_last_error_msg()
                ]);
                return false;
            }

            if (isset($responseData['errorCode'])) {
                $this->logger->error('Kiyoh API: Product sync error', [
                    'error' => $responseData,
                    'product' => $productData['product_code'] ?? 'unknown',
                    'http_code' => $httpCode
                ]);
                return false;
            }

            $this->logger->info('Kiyoh API: Product synced successfully', [
                'product' => $productData['product_code'] ?? 'unknown',
                'http_code' => $httpCode
            ]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh API: Product sync exception', [
                'exception' => $e->getMessage(),
                'product' => $productData['product_code'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function sendBulkProductSyncRequest(array $productsData, int $storeId): array
    {
        $server = $this->getServerUrl($storeId);
        $apiToken = $this->getConfig(self::CONFIG_PATH_API_TOKEN, $storeId);
        $locationId = $this->getConfig(self::CONFIG_PATH_LOCATION_ID, $storeId);

        $result = ['success' => 0, 'failed' => 0, 'errors' => []];

        if (!$apiToken) {
            $this->logger->error('Kiyoh API: Missing API token for bulk product sync');
            $result['failed'] = count($productsData);
            $result['errors'][] = 'Missing API token';
            return $result;
        }

        if (empty($productsData)) {
            $this->logger->warning('Kiyoh API: Empty products data for bulk sync');
            return $result;
        }

        $requestData = [
            'location_id' => (string) $locationId,
            'products' => $productsData
        ];

        $url = sprintf('%s/v1/location/product/external/bulk', $server);

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Failed to initialize cURL');
            }
            
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_INVITATION * 5);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Publication-Api-Token: ' . $apiToken,
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $this->logger->error('Kiyoh API: Bulk product sync cURL failed', [
                    'curl_error' => $curlError,
                    'url' => $url,
                    'product_count' => count($productsData)
                ]);
                $result['failed'] = count($productsData);
                $result['errors'][] = 'cURL request failed: ' . $curlError;
                return $result;
            }

            $responseData = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Kiyoh API: Invalid JSON response for bulk sync', [
                    'http_code' => $httpCode,
                    'response' => substr($response, 0, 500),
                    'json_error' => json_last_error_msg(),
                    'product_count' => count($productsData)
                ]);
                $result['failed'] = count($productsData);
                $result['errors'][] = 'Invalid JSON response: ' . json_last_error_msg();
                return $result;
            }

            if (isset($responseData['errorCode'])) {
                $this->logger->error('Kiyoh API: Bulk product sync error', [
                    'error' => $responseData,
                    'http_code' => $httpCode,
                    'product_count' => count($productsData)
                ]);
                $result['failed'] = count($productsData);
                $result['errors'][] = $responseData['errorCode'] . ': ' . ($responseData['message'] ?? 'Unknown error');
                return $result;
            }

            $result['success'] = count($productsData);
            $this->logger->info('Kiyoh API: Bulk products synced successfully', [
                'count' => count($productsData),
                'http_code' => $httpCode
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh API: Bulk product sync exception', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'product_count' => count($productsData)
            ]);
            $result['failed'] = count($productsData);
            $result['errors'][] = 'Exception: ' . $e->getMessage();
        }

        return $result;
    }

    private function detectLanguageFromOrder(OrderInterface $order, int $storeId): string
    {
        $fallbackLanguage = $this->getConfig(self::CONFIG_PATH_FALLBACK_LANGUAGE, $storeId) ?: 'en';

        try {
            $currentLocale = $this->localeResolver->getLocale();
            if ($currentLocale) {
                return $this->mapMagentoLocaleToKiyohLanguage($currentLocale, $fallbackLanguage);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Kiyoh API: Could not get current locale, falling back to store locale', ['exception' => $e->getMessage()]);
        }

        try {
            $storeLocale = $this->scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORE,
                $order->getStoreId()
            );

            if ($storeLocale) {
                return $this->mapMagentoLocaleToKiyohLanguage($storeLocale, $fallbackLanguage);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Kiyoh API: Could not detect language from order', ['exception' => $e->getMessage()]);
        }

        return $fallbackLanguage;
    }

    private function mapMagentoLocaleToKiyohLanguage(string $magentoLocale, string $fallback = 'en'): string
    {
        $localeToKiyohMap = [
            'nl_NL' => 'nl',
            'fr_FR' => 'fr', 'fr_CA' => 'fr',
            'de_DE' => 'de', 'de_AT' => 'de', 'de_CH' => 'de',
            'en_US' => 'en', 'en_GB' => 'en', 'en_AU' => 'en', 'en_CA' => 'en', 'en_NZ' => 'en',
            'da_DK' => 'da',
            'hu_HU' => 'hu',
            'bg_BG' => 'bg',
            'ro_RO' => 'ro',
            'hr_HR' => 'hr',
            'ja_JP' => 'ja',
            'es_ES' => 'es', 'es_AR' => 'es', 'es_CL' => 'es', 'es_CO' => 'es', 'es_MX' => 'es', 'es_PE' => 'es', 'es_VE' => 'es',
            'it_IT' => 'it', 'it_CH' => 'it',
            'pt_PT' => 'pt',
            'tr_TR' => 'tr',
            'nb_NO' => 'no', 'nn_NO' => 'no',
            'sv_SE' => 'sv',
            'fi_FI' => 'fi',
            'pt_BR' => 'pt',
            'pl_PL' => 'pl',
            'sl_SI' => 'sl',
            'zh_Hans_CN' => 'zh', 'zh_Hant_HK' => 'zh', 'zh_Hant_TW' => 'zh',
            'ru_RU' => 'ru',
            'el_GR' => 'gr',
            'cs_CZ' => 'cs',
            'et_EE' => 'et',
            'lt_LT' => 'lt',
            'lv_LV' => 'lv',
            'sk_SK' => 'sk',
        ];

        if (isset($localeToKiyohMap[$magentoLocale])) {
            return $localeToKiyohMap[$magentoLocale];
        }

        $languageCode = substr($magentoLocale, 0, 2);
        $supportedKiyohLanguages = array_unique(array_values($localeToKiyohMap));
        
        if (in_array($languageCode, $supportedKiyohLanguages)) {
            return $languageCode;
        }

        $this->logger->debug('Kiyoh API: Unsupported language detected, using fallback', [
            'magento_locale' => $magentoLocale,
            'language_code' => $languageCode,
            'fallback' => $fallback
        ]);

        return $fallback;
    }

    private function getServerUrl(int $storeId): string
    {
        $server = $this->getConfig(self::CONFIG_PATH_SERVER, $storeId);

        $serverMap = [
            'kiyoh.com' => 'https://www.kiyoh.com',
            'klantenvertellen.nl' => 'https://www.klantenvertellen.nl'
        ];

        return $serverMap[$server] ?? 'https://www.kiyoh.com';
    }

    private function isEnabled(int $storeId): bool
    {
        return (bool) $this->getConfig(self::CONFIG_PATH_ENABLED, $storeId);
    }

    private function isInvitationsEnabled(int $storeId): bool
    {
        return (bool) $this->getConfig(self::CONFIG_PATH_INVITATIONS_ENABLED, $storeId);
    }

    private function getConfig(string $path, int $storeId)
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        
        // Decrypt API token if it's encrypted
        if ($path === self::CONFIG_PATH_API_TOKEN && $value) {
            $value = $this->encryptor->decrypt($value);
        }
        
        return $value;
    }

    public function validateLegacyCredentials(string $server, string $connector, string $companyId): array
    {
        $serverUrl = $this->mapLegacyServer($server);
        $url = sprintf(
            '%s/xml/recent_company_reviews.xml?connectorcode=%s&company_id=%s',
            $serverUrl,
            urlencode($connector),
            urlencode($companyId)
        );

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_REVIEWS);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                return ['success' => false, 'message' => 'Connection failed'];
            }

            if ($httpCode !== 200) {
                return ['success' => false, 'message' => 'Invalid HTTP response: ' . $httpCode];
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response);
            libxml_use_internal_errors(false);

            if ($xml === false) {
                return ['success' => false, 'message' => 'Invalid XML response'];
            }

            if (isset($xml->error)) {
                return ['success' => false, 'message' => (string) $xml->error];
            }

            if (!isset($xml->company)) {
                return ['success' => false, 'message' => 'Invalid credentials or company not found'];
            }

            return ['success' => true, 'message' => 'Credentials validated successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function validateNewApiCredentials(string $server, string $hash, string $locationId): array
    {
        $serverUrl = $this->mapNewApiServer($server);
        $url = sprintf(
            '%s/v1/publication/review/external/location/statistics?locationId=%s',
            $serverUrl,
            urlencode($locationId)
        );

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_REVIEWS);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Publication-Api-Token: ' . $hash
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                return ['success' => false, 'message' => 'Connection failed'];
            }

            if ($httpCode === 401 || $httpCode === 403) {
                return ['success' => false, 'message' => 'Invalid API token'];
            }

            if ($httpCode !== 200) {
                return ['success' => false, 'message' => 'API request failed with HTTP ' . $httpCode];
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['success' => false, 'message' => 'Invalid JSON response from API'];
            }

            if (isset($data['errorCode'])) {
                $errorMsg = $data['errorCode'];
                if (strpos($errorMsg, 'location') !== false || strpos($errorMsg, 'Location') !== false) {
                    return ['success' => false, 'message' => 'Invalid Location ID'];
                }
                return ['success' => false, 'message' => $errorMsg];
            }

            if (!isset($data['locationName'])) {
                return ['success' => false, 'message' => 'Location not found - check Location ID'];
            }

            return ['success' => true, 'message' => 'Credentials validated successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function mapLegacyServer(string $server): string
    {
        $serverMap = [
            'kiyoh.nl' => 'https://www.kiyoh.nl',
            'kiyoh.com' => 'https://www.kiyoh.com'
        ];

        return $serverMap[$server] ?? 'https://www.kiyoh.nl';
    }

    private function mapNewApiServer(string $server): string
    {
        $serverMap = [
            'klantenvertellen.nl' => 'https://www.klantenvertellen.nl',
            'newkiyoh.com' => 'https://www.kiyoh.com',
            'kiyoh.com' => 'https://www.kiyoh.com'
        ];

        return $serverMap[$server] ?? 'https://www.kiyoh.com';
    }
}
