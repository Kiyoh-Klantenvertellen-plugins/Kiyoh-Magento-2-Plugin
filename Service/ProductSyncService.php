<?php

namespace Kiyoh\Reviews\Service;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Kiyoh\Reviews\Api\ApiServiceInterface;
use Psr\Log\LoggerInterface;

class ProductSyncService
{
    private const CONFIG_PATH_PRODUCT_SYNC_ENABLED = 'kiyoh_reviews/product_sync/enabled';
    private const CONFIG_PATH_EXCLUDED_TYPES = 'kiyoh_reviews/product_sync/excluded_product_types';
    private const CONFIG_PATH_EXCLUDED_CODES = 'kiyoh_reviews/product_sync/excluded_product_codes';
    
    private const BATCH_SIZE = 200;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;
    
    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;
    
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var ApiServiceInterface
     */
    private $apiService;
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        CollectionFactory $productCollectionFactory,
        ScopeConfigInterface $scopeConfig,
        ApiServiceInterface $apiService,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->scopeConfig = $scopeConfig;
        $this->apiService = $apiService;
        $this->logger = $logger;
    }

    public function syncAllProducts(int $storeId = 0, callable $progressCallback = null): array
    {
        try {
            if (!$this->isProductSyncEnabled($storeId)) {
                $this->logger->info('Kiyoh Product Sync: Sync disabled', ['store_id' => $storeId]);
                return [
                    'success' => false,
                    'message' => 'Product sync is disabled',
                    'synced' => 0,
                    'failed' => 0,
                    'errors' => [],
                    'total' => 0
                ];
            }

            $this->logger->info('Kiyoh Product Sync: Starting bulk product sync', ['store_id' => $storeId]);

            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect(['name', 'sku', 'image', 'url_key', 'gtin', 'mpn', 'brand']);
            
            if ($storeId > 0) {
                $collection->addStoreFilter($storeId);
            }

            $excludedTypes = $this->getExcludedProductTypes($storeId);
            if (!empty($excludedTypes)) {
                $collection->addAttributeToFilter('type_id', ['nin' => $excludedTypes]);
            }

            $excludedCodes = $this->getExcludedProductCodes($storeId);
            if (!empty($excludedCodes)) {
                $collection->addAttributeToFilter('sku', ['nin' => $excludedCodes]);
            }

            $totalProducts = $collection->getSize();
            $this->logger->info('Kiyoh Product Sync: Found products to sync', ['total' => $totalProducts]);

            if ($totalProducts === 0) {
                return [
                    'success' => true,
                    'message' => 'No products found to sync',
                    'synced' => 0,
                    'failed' => 0,
                    'errors' => [],
                    'total' => 0
                ];
            }

            $results = [
                'success' => true,
                'message' => '',
                'synced' => 0,
                'failed' => 0,
                'errors' => [],
                'total' => $totalProducts,
                'total_batches' => 0
            ];

            $collection->setPageSize(self::BATCH_SIZE);
            $pages = $collection->getLastPageNumber();
            $results['total_batches'] = $pages;

            for ($currentPage = 1; $currentPage <= $pages; $currentPage++) {
                try {
                    $collection->setCurPage($currentPage);
                    $products = [];

                    foreach ($collection as $product) {
                        try {
                            if ($this->shouldSyncProduct($product, $storeId)) {
                                $products[] = $product;
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning('Kiyoh Product Sync: Error checking product eligibility', [
                                'product_id' => $product->getId(),
                                'exception' => $e->getMessage()
                            ]);
                        }
                    }

                    if (!empty($products)) {
                        $batchResult = $this->apiService->syncProductsBulk($products);
                        $results['synced'] += $batchResult['success'];
                        $results['failed'] += $batchResult['failed'];
                        $results['errors'] = array_merge($results['errors'], $batchResult['errors']);

                        $this->logger->info('Kiyoh Product Sync: Batch completed', [
                            'page' => $currentPage,
                            'total_pages' => $pages,
                            'batch_success' => $batchResult['success'],
                            'batch_failed' => $batchResult['failed']
                        ]);

                        if ($progressCallback) {
                            try {
                                $progressCallback([
                                    'current_batch' => $currentPage,
                                    'total_batches' => $pages,
                                    'synced' => $results['synced'],
                                    'failed' => $results['failed'],
                                    'total' => $totalProducts,
                                    'batch_success' => $batchResult['success'],
                                    'batch_failed' => $batchResult['failed']
                                ]);
                            } catch (\Exception $e) {
                                $this->logger->warning('Kiyoh Product Sync: Progress callback failed', [
                                    'exception' => $e->getMessage()
                                ]);
                            }
                        }
                    }

                    $collection->clear();
                } catch (\Exception $e) {
                    $this->logger->error('Kiyoh Product Sync: Batch processing error', [
                        'page' => $currentPage,
                        'exception' => $e->getMessage()
                    ]);
                    $results['errors'][] = 'Batch ' . $currentPage . ' error: ' . $e->getMessage();
                }
            }

            $results['message'] = sprintf(
                'Bulk sync completed: %d synced, %d failed',
                $results['synced'],
                $results['failed']
            );

            $this->logger->info('Kiyoh Product Sync: Bulk sync completed', $results);

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Product Sync: Critical error during sync', [
                'store_id' => $storeId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Critical error: ' . $e->getMessage(),
                'synced' => 0,
                'failed' => 0,
                'errors' => [$e->getMessage()],
                'total' => 0
            ];
        }
    }

    public function syncProduct($product, int $storeId = null): bool
    {
        try {
            $storeId = $storeId ?? ($product->getStoreId() ?: 0);

            if (!$this->isProductSyncEnabled($storeId)) {
                $this->logger->debug('Kiyoh Product Sync: Sync disabled for store', [
                    'store_id' => $storeId,
                    'product_sku' => $product->getSku() ?? 'unknown'
                ]);
                return false;
            }

            if (!$this->shouldSyncProduct($product, $storeId)) {
                $this->logger->debug('Kiyoh Product Sync: Product excluded from sync', [
                    'product_sku' => $product->getSku() ?? 'unknown',
                    'store_id' => $storeId
                ]);
                return false;
            }

            return $this->apiService->syncProduct($product);
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Product Sync: Exception during product sync', [
                'product_sku' => $product->getSku() ?? 'unknown',
                'store_id' => $storeId ?? 0,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function shouldSyncProduct($product, int $storeId): bool
    {
        try {
            if (!$product || !$product->getSku() || !$product->getName()) {
                return false;
            }

            $excludedTypes = $this->getExcludedProductTypes($storeId);
            if (in_array($product->getTypeId(), $excludedTypes)) {
                return false;
            }

            $excludedCodes = $this->getExcludedProductCodes($storeId);
            if (in_array($product->getSku(), $excludedCodes)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Kiyoh Product Sync: Error checking product eligibility', [
                'product_id' => $product ? $product->getId() : 'null',
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function isProductSyncEnabled(int $storeId): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PRODUCT_SYNC_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function getExcludedProductTypes(int $storeId): array
    {
        $excludedTypes = $this->scopeConfig->getValue(
            self::CONFIG_PATH_EXCLUDED_TYPES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $excludedTypes ? explode(',', $excludedTypes) : [];
    }

    private function getExcludedProductCodes(int $storeId): array
    {
        $excludedCodes = $this->scopeConfig->getValue(
            self::CONFIG_PATH_EXCLUDED_CODES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!$excludedCodes) {
            return [];
        }

        return array_map('trim', explode(',', $excludedCodes));
    }
}