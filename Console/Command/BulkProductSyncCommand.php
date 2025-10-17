<?php

namespace Kiyoh\Reviews\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Kiyoh\Reviews\Service\ProductSyncService;
use Magento\Store\Model\StoreManagerInterface;

class BulkProductSyncCommand extends Command
{
    /**
     * @var ProductSyncService
     */
    private $productSyncService;
    
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        ProductSyncService $productSyncService,
        StoreManagerInterface $storeManager,
        string $name = null
    ) {
        $this->productSyncService = $productSyncService;
        $this->storeManager = $storeManager;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('kiyoh:product:sync')
            ->setDescription('Bulk sync all products to Kiyoh/Klantenvertellen')
            ->addOption(
                'store-id',
                's',
                InputOption::VALUE_OPTIONAL,
                'Store ID to sync products for (default: all stores)',
                null
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be synced without actually syncing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeId = $input->getOption('store-id');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('<info>DRY RUN MODE - No actual sync will be performed</info>');
        }

        if ($storeId !== null) {
            $storeId = (int) $storeId;
            $output->writeln(sprintf('<info>Syncing products for store ID: %d</info>', $storeId));
            
            if ($dryRun) {
                $output->writeln('<comment>Would sync products for this store</comment>');
                return Command::SUCCESS;
            }

            $result = $this->syncForStore($storeId, $output);
        } else {
            $output->writeln('<info>Syncing products for all stores</info>');
            
            if ($dryRun) {
                $stores = $this->storeManager->getStores();
                foreach ($stores as $store) {
                    $output->writeln(sprintf('<comment>Would sync products for store: %s (ID: %d)</comment>', 
                        $store->getName(), $store->getId()));
                }
                return Command::SUCCESS;
            }

            $result = $this->syncForAllStores($output);
        }

        if ($result['success']) {
            $output->writeln(sprintf(
                '<info>Sync completed successfully: %d products synced, %d failed</info>',
                $result['synced'],
                $result['failed']
            ));

            if (!empty($result['errors'])) {
                $output->writeln('<error>Errors encountered:</error>');
                foreach ($result['errors'] as $error) {
                    $output->writeln(sprintf('<error>- %s</error>', $error));
                }
            }

            return Command::SUCCESS;
        } else {
            $output->writeln(sprintf('<error>Sync failed: %s</error>', $result['message']));
            return Command::FAILURE;
        }
    }

    private function syncForStore(int $storeId, OutputInterface $output): array
    {
        $output->writeln(sprintf('Starting product sync for store ID: %d', $storeId));
        
        $result = $this->productSyncService->syncAllProducts($storeId);
        
        $output->writeln(sprintf(
            'Store %d: %d synced, %d failed',
            $storeId,
            $result['synced'],
            $result['failed']
        ));

        return $result;
    }

    private function syncForAllStores(OutputInterface $output): array
    {
        $stores = $this->storeManager->getStores();
        $totalResult = [
            'success' => true,
            'synced' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $progressBar = new ProgressBar($output, count($stores));
        $progressBar->start();

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $result = $this->productSyncService->syncAllProducts($storeId);

            $totalResult['synced'] += $result['synced'];
            $totalResult['failed'] += $result['failed'];
            $totalResult['errors'] = array_merge($totalResult['errors'], $result['errors']);

            if (!$result['success']) {
                $totalResult['success'] = false;
            }

            $output->writeln(sprintf(
                "\nStore %s (ID: %d): %d synced, %d failed",
                $store->getName(),
                $storeId,
                $result['synced'],
                $result['failed']
            ));

            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');

        return $totalResult;
    }
}