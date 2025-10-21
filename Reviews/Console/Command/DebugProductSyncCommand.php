<?php

namespace Kiyoh\Reviews\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

class DebugProductSyncCommand extends Command
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        string $name = null
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('kiyoh:debug:product-sync')
            ->setDescription('Debug product sync configuration and settings')
            ->addOption(
                'sku',
                null,
                InputOption::VALUE_OPTIONAL,
                'Check specific product SKU'
            )
            ->addOption(
                'store-id',
                's',
                InputOption::VALUE_OPTIONAL,
                'Store ID to check',
                0
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeId = (int) $input->getOption('store-id');
        $sku = $input->getOption('sku');

        $output->writeln('<info>Kiyoh Product Sync Debug Information</info>');
        $output->writeln('=====================================');

        // Check store info
        if ($storeId > 0) {
            try {
                $store = $this->storeManager->getStore($storeId);
                $output->writeln(sprintf('Store: %s (ID: %d)', $store->getName(), $storeId));
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>Invalid store ID: %d</error>', $storeId));
                return Command::FAILURE;
            }
        } else {
            $output->writeln('Store: Default (ID: 0)');
        }

        $output->writeln('');

        // Check configuration
        $output->writeln('<info>Configuration Check:</info>');
        
        $moduleEnabled = $this->getConfigValue('kiyoh_reviews/api_settings/enabled', $storeId);
        $output->writeln(sprintf('Module Enabled: %s', $moduleEnabled ? 'Yes' : 'No'));
        
        $productSyncEnabled = $this->getConfigValue('kiyoh_reviews/product_sync/enabled', $storeId);
        $output->writeln(sprintf('Product Sync Enabled: %s', $productSyncEnabled ? 'Yes' : 'No'));
        
        $autoSyncEnabled = $this->getConfigValue('kiyoh_reviews/product_sync/auto_sync_enabled', $storeId);
        $output->writeln(sprintf('Auto Sync Enabled: %s', $autoSyncEnabled ? 'Yes' : 'No'));

        $locationId = $this->getConfigValue('kiyoh_reviews/api_settings/location_id', $storeId);
        $output->writeln(sprintf('Location ID: %s', $locationId ?: 'Not set'));

        $apiToken = $this->getConfigValue('kiyoh_reviews/api_settings/api_token', $storeId);
        $output->writeln(sprintf('API Token: %s', $apiToken ? 'Set (encrypted)' : 'Not set'));

        $excludedTypes = $this->getConfigValue('kiyoh_reviews/product_sync/excluded_product_types', $storeId);
        $output->writeln(sprintf('Excluded Product Types: %s', $excludedTypes ?: 'None'));

        $excludedCodes = $this->getConfigValue('kiyoh_reviews/product_sync/excluded_product_codes', $storeId);
        $output->writeln(sprintf('Excluded Product Codes: %s', $excludedCodes ?: 'None'));

        $output->writeln('');

        // Check if sync would work
        $canSync = $moduleEnabled && $productSyncEnabled && $autoSyncEnabled && $locationId && $apiToken;
        $output->writeln(sprintf('<info>Auto Sync Will Work: %s</info>', $canSync ? 'Yes' : 'No'));

        if (!$canSync) {
            $output->writeln('<comment>Issues preventing auto sync:</comment>');
            if (!$moduleEnabled) $output->writeln('- Module is disabled');
            if (!$productSyncEnabled) $output->writeln('- Product sync is disabled');
            if (!$autoSyncEnabled) $output->writeln('- Auto sync is disabled');
            if (!$locationId) $output->writeln('- Location ID is not configured');
            if (!$apiToken) $output->writeln('- API token is not configured');
        }

        // Check specific product if provided
        if ($sku) {
            $output->writeln('');
            $output->writeln(sprintf('<info>Product Check: %s</info>', $sku));
            
            try {
                $product = $this->productRepository->get($sku, false, $storeId);
                
                $output->writeln(sprintf('Product ID: %d', $product->getId()));
                $output->writeln(sprintf('Product Name: %s', $product->getName()));
                $output->writeln(sprintf('Product Type: %s', $product->getTypeId()));
                $output->writeln(sprintf('Product Status: %s', $product->getStatus() == 1 ? 'Enabled' : 'Disabled'));
                
                // Check if product would be excluded
                $excludedTypesList = $excludedTypes ? explode(',', $excludedTypes) : [];
                $excludedCodesList = $excludedCodes ? array_map('trim', explode(',', $excludedCodes)) : [];
                
                $typeExcluded = in_array($product->getTypeId(), $excludedTypesList);
                $codeExcluded = in_array($product->getSku(), $excludedCodesList);
                
                $output->writeln(sprintf('Excluded by Type: %s', $typeExcluded ? 'Yes' : 'No'));
                $output->writeln(sprintf('Excluded by Code: %s', $codeExcluded ? 'Yes' : 'No'));
                
                $wouldSync = $canSync && !$typeExcluded && !$codeExcluded && $product->getName() && $product->getSku();
                $output->writeln(sprintf('<info>Would Sync: %s</info>', $wouldSync ? 'Yes' : 'No'));
                
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>Product not found: %s</error>', $e->getMessage()));
            }
        }

        $output->writeln('');
        $output->writeln('<comment>To enable auto sync:</comment>');
        $output->writeln('1. Go to Admin > Stores > Configuration > Kiyoh > Reviews Configuration');
        $output->writeln('2. Enable "Enable Kiyoh Reviews" in API Settings');
        $output->writeln('3. Configure Location ID and API Token in API Settings');
        $output->writeln('4. Enable "Enable Product Sync" in Product Synchronization');
        $output->writeln('5. Enable "Auto Sync on Product Changes" in Product Synchronization');

        return Command::SUCCESS;
    }

    private function getConfigValue(string $path, int $storeId)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}