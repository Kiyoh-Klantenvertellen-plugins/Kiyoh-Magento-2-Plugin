<?php

namespace Kiyoh\Reviews\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class CatalogProductDeleteAfter implements ObserverInterface
{
    private const CONFIG_PATH_PRODUCT_SYNC_ENABLED = 'kiyoh_reviews/product_sync/enabled';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $product = $observer->getEvent()->getProduct();
            
            if (!$product) {
                $this->logger->warning('Kiyoh Product Sync: No product in delete event');
                return;
            }
            
            $storeId = $product->getStoreId() ?: 0;

            if (!$this->isProductSyncEnabled($storeId)) {
                return;
            }

            $this->logger->info('Kiyoh Product Sync: Product deleted from catalog', [
                'product_id' => $product->getId(),
                'sku' => $product->getSku(),
                'note' => 'Product deletion does not automatically remove from Kiyoh - manual cleanup may be required'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Product Sync: Exception in delete observer', [
                'exception' => $e->getMessage()
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
}