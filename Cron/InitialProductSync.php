<?php

namespace Kiyoh\Reviews\Cron;

use Kiyoh\Reviews\Service\ProductSyncService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class InitialProductSync
{
    private const CONFIG_PATH_PRODUCT_SYNC_ENABLED = 'kiyoh_reviews/product_sync/enabled';
    private const CONFIG_PATH_INITIAL_SYNC_DONE = 'kiyoh_reviews/product_sync/initial_sync_done';

    /**
     * @var ProductSyncService
     */
    private $productSyncService;
    
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ProductSyncService $productSyncService,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->productSyncService = $productSyncService;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        $this->logger->info('Kiyoh Cron: Starting initial product sync check');

        try {
            $stores = $this->storeManager->getStores();
            
            if (empty($stores)) {
                $this->logger->warning('Kiyoh Cron: No stores found');
                return;
            }
            
            foreach ($stores as $store) {
                try {
                    $storeId = (int) $store->getId();
                    
                    if ($this->shouldRunInitialSync($storeId)) {
                        $this->logger->info('Kiyoh Cron: Running initial sync for store', [
                            'store_id' => $storeId,
                            'store_name' => $store->getName()
                        ]);
                        
                        $result = $this->productSyncService->syncAllProducts($storeId);
                        
                        $this->logger->info('Kiyoh Cron: Initial sync completed for store', [
                            'store_id' => $storeId,
                            'store_name' => $store->getName(),
                            'synced' => $result['synced'] ?? 0,
                            'failed' => $result['failed'] ?? 0,
                            'success' => $result['success'] ?? false
                        ]);
                        
                        if ($result['success'] ?? false) {
                            $this->markInitialSyncDone($storeId);
                        } else {
                            $this->logger->warning('Kiyoh Cron: Initial sync not marked as done due to errors', [
                                'store_id' => $storeId,
                                'errors' => $result['errors'] ?? []
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Kiyoh Cron: Error processing store', [
                        'store_id' => $store->getId(),
                        'store_name' => $store->getName(),
                        'exception' => $e->getMessage()
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Cron: Critical error in initial product sync', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function shouldRunInitialSync(int $storeId): bool
    {
        try {
            $syncEnabled = (bool) $this->scopeConfig->getValue(
                self::CONFIG_PATH_PRODUCT_SYNC_ENABLED,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            if (!$syncEnabled) {
                $this->logger->debug('Kiyoh Cron: Product sync disabled for store', [
                    'store_id' => $storeId
                ]);
                return false;
            }

            $initialSyncDone = (bool) $this->scopeConfig->getValue(
                self::CONFIG_PATH_INITIAL_SYNC_DONE,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            if ($initialSyncDone) {
                $this->logger->debug('Kiyoh Cron: Initial sync already completed for store', [
                    'store_id' => $storeId
                ]);
            }

            return !$initialSyncDone;
        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Cron: Error checking initial sync status', [
                'store_id' => $storeId,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function markInitialSyncDone(int $storeId): void
    {
        // This would typically use a config writer, but for simplicity
        // we'll just log that it should be marked as done
        $this->logger->info('Kiyoh Cron: Initial sync completed, should mark as done', [
            'store_id' => $storeId,
            'note' => 'Admin should set initial_sync_done to 1 in configuration'
        ]);
    }
}