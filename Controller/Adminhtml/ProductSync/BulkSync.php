<?php

namespace Kiyoh\Reviews\Controller\Adminhtml\ProductSync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Kiyoh\Reviews\Service\ProductSyncService;
use Psr\Log\LoggerInterface;

class BulkSync extends Action
{
    const ADMIN_RESOURCE = 'Kiyoh_Reviews::config';

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;
    
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    
    /**
     * @var ProductSyncService
     */
    private $productSyncService;
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        StoreManagerInterface $storeManager,
        ProductSyncService $productSyncService,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->storeManager = $storeManager;
        $this->productSyncService = $productSyncService;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        
        try {
            $storeId = (int) $this->getRequest()->getParam('store_id', 0);
            
            if ($storeId > 0) {
                $store = $this->storeManager->getStore($storeId);
                $storeName = $store->getName();
            } else {
                $storeName = 'All Stores';
            }

            $this->logger->info('Kiyoh Admin: Starting bulk product sync', [
                'store_id' => $storeId,
                'store_name' => $storeName,
                'admin_user' => $this->_auth->getUser()->getUserName()
            ]);

            $session = $this->_objectManager->get(\Magento\Framework\Session\SessionManagerInterface::class);
            $session->setData('kiyoh_sync_progress', [
                'status' => 'running',
                'percentage' => 0,
                'current_batch' => 0,
                'total_batches' => 0,
                'synced' => 0,
                'failed' => 0,
                'total' => 0
            ]);

            $progressCallback = function($progress) use ($session) {
                $percentage = $progress['total'] > 0 
                    ? round(($progress['synced'] + $progress['failed']) / $progress['total'] * 100) 
                    : 0;
                
                $session->setData('kiyoh_sync_progress', [
                    'status' => 'running',
                    'percentage' => $percentage,
                    'current_batch' => $progress['current_batch'],
                    'total_batches' => $progress['total_batches'],
                    'synced' => $progress['synced'],
                    'failed' => $progress['failed'],
                    'total' => $progress['total'],
                    'batch_success' => $progress['batch_success'],
                    'batch_failed' => $progress['batch_failed']
                ]);
                
                $this->logger->info('Kiyoh Product Sync: Progress update', [
                    'batch' => $progress['current_batch'],
                    'total_batches' => $progress['total_batches'],
                    'percentage' => $percentage,
                    'synced' => $progress['synced'],
                    'failed' => $progress['failed']
                ]);
            };

            if ($storeId > 0) {
                $syncResult = $this->productSyncService->syncAllProducts($storeId, $progressCallback);
            } else {
                $syncResult = $this->syncAllStores($progressCallback);
            }

            if ($syncResult['success']) {
                $message = sprintf(
                    'Bulk sync completed successfully for %s: %d products synced, %d failed',
                    $storeName,
                    $syncResult['synced'],
                    $syncResult['failed']
                );

                $result->setData([
                    'success' => true,
                    'message' => $message,
                    'synced' => $syncResult['synced'],
                    'failed' => $syncResult['failed'],
                    'total' => $syncResult['total'] ?? 0,
                    'errors' => $syncResult['errors'],
                    'total_batches' => $syncResult['total_batches'] ?? 0
                ]);

                $this->messageManager->addSuccessMessage($message);
            } else {
                $errorMessage = sprintf(
                    'Bulk sync failed for %s: %s',
                    $storeName,
                    $syncResult['message']
                );

                $result->setData([
                    'success' => false,
                    'message' => $errorMessage,
                    'synced' => $syncResult['synced'] ?? 0,
                    'failed' => $syncResult['failed'] ?? 0,
                    'total' => $syncResult['total'] ?? 0,
                    'errors' => $syncResult['errors'] ?? [],
                    'total_batches' => $syncResult['total_batches'] ?? 0
                ]);

                $this->messageManager->addErrorMessage($errorMessage);
            }

        } catch (\Exception $e) {
            $this->logger->error('Kiyoh Admin: Bulk sync exception', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $result->setData([
                'success' => false,
                'message' => 'An error occurred during bulk sync: ' . $e->getMessage(),
                'synced' => 0,
                'failed' => 0,
                'total' => 0,
                'errors' => [$e->getMessage()],
                'total_batches' => 0
            ]);

            $this->messageManager->addErrorMessage('Bulk sync failed: ' . $e->getMessage());
        }

        return $result;
    }

    private function syncAllStores(callable $progressCallback = null): array
    {
        $stores = $this->storeManager->getStores();
        $totalResult = [
            'success' => true,
            'synced' => 0,
            'failed' => 0,
            'errors' => [],
            'total' => 0
        ];

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $result = $this->productSyncService->syncAllProducts($storeId, $progressCallback);

            $totalResult['synced'] += $result['synced'];
            $totalResult['failed'] += $result['failed'];
            $totalResult['total'] += $result['total'] ?? 0;
            $totalResult['errors'] = array_merge($totalResult['errors'], $result['errors']);

            if (!$result['success']) {
                $totalResult['success'] = false;
            }

            $this->logger->info('Kiyoh Admin: Store sync completed', [
                'store_id' => $storeId,
                'store_name' => $store->getName(),
                'synced' => $result['synced'],
                'failed' => $result['failed']
            ]);
        }

        return $totalResult;
    }
}