<?php

namespace Kiyoh\Reviews\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Kiyoh\Reviews\Api\ApiServiceInterface;
use Psr\Log\LoggerInterface;

class CatalogProductSaveAfter implements ObserverInterface
{
    private const CONFIG_PATH_PRODUCT_SYNC_ENABLED = 'kiyoh_reviews/product_sync/enabled';
    private const CONFIG_PATH_AUTO_SYNC_ENABLED = 'kiyoh_reviews/product_sync/auto_sync_enabled';
    private const CONFIG_PATH_EXCLUDED_TYPES = 'kiyoh_reviews/product_sync/excluded_product_types';
    private const CONFIG_PATH_EXCLUDED_CODES = 'kiyoh_reviews/product_sync/excluded_product_codes';

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
        ScopeConfigInterface $scopeConfig,
        ApiServiceInterface $apiService,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->apiService = $apiService;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $product = $observer->getEvent()->getProduct();
            
            if (!$product) {
                $this->logger->warning('Kiyoh Product Sync: No product in event');
                return;
            }
            
            $storeId = $product->getStoreId() ?: 0;

            if (!$this->isProductSyncEnabled($storeId) || !$this->isAutoSyncEnabled($storeId)) {
                return;
            }

            if (!$this->shouldSyncProduct($product, $storeId)) {
                $this->logger->debug('Kiyoh Product Sync: Product excluded from sync', [
                    'product_id' => $product->getId(),
                    'sku' => $product->getSku(),
                    'type' => $product->getTypeId()
                ]);
                return;
            }

            try {
                $success = $this->apiService->syncProduct($product);
                
                if ($success) {
                    $this->logger->info('Kiyoh Product Sync: Product synced successfully', [
                        'product_id' => $product->getId(),
                        'sku' => $product->getSku()
                    ]);
                } else {
                    $this->logger->warning('Kiyoh Product Sync: Product sync failed', [
                        'product_id' => $product->getId(),
                        'sku' => $product->getSku()
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('Kiyoh Product Sync: Exception during product sync', [
                    'product_id' => $product->getId(),
                    'sku' => $product->getSku(),
                    'exception' => $e->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Product Sync: Critical observer exception', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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

    private function isAutoSyncEnabled(int $storeId): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::CONFIG_PATH_AUTO_SYNC_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function shouldSyncProduct($product, int $storeId): bool
    {
        if (!$product->getSku() || !$product->getName()) {
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