<?php

namespace Kiyoh\Reviews\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ValidateProductDataCommand extends Command
{
    private const OPTION_SKU = 'sku';

    /**
     * @var State
     */
    private $appState;
    
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;
    
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    public function __construct(
        State $appState,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        string $name = null
    ) {
        $this->appState = $appState;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('kiyoh:validate-product-data')
            ->setDescription('Validate product data format for Kiyoh API')
            ->addOption(
                self::OPTION_SKU,
                null,
                InputOption::VALUE_OPTIONAL,
                'Specific product SKU to validate (optional)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set
        }

        $sku = $input->getOption(self::OPTION_SKU);

        $output->writeln('');
        $output->writeln('<info>🔍 Kiyoh Product Data Validation</info>');
        $output->writeln('<info>================================</info>');
        $output->writeln('');

        try {
            if ($sku) {
                $product = $this->productRepository->get($sku);
                $products = [$product];
                $output->writeln("Validating specific product: {$sku}");
            } else {
                $searchCriteria = $this->searchCriteriaBuilder
                    ->setPageSize(3)
                    ->setCurrentPage(1)
                    ->create();
                
                $productList = $this->productRepository->getList($searchCriteria);
                $products = array_values($productList->getItems());
                $output->writeln("Validating first 3 products from catalog");
            }

            if (empty($products)) {
                $output->writeln('<error>❌ No products found</error>');
                return Cli::RETURN_FAILURE;
            }

            foreach ($products as $product) {
                $this->validateProduct($product, $output);
                $output->writeln('');
            }

        } catch (\Exception $e) {
            $output->writeln("<error>❌ Error: {$e->getMessage()}</error>");
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('<info>✅ Product data validation complete</info>');
        return Cli::RETURN_SUCCESS;
    }

    private function validateProduct($product, OutputInterface $output): void
    {
        $output->writeln("<comment>📦 Product: {$product->getName()} (SKU: {$product->getSku()})</comment>");
        
        // Check required fields
        $issues = [];
        $warnings = [];
        
        // Product Code (SKU)
        $sku = $product->getSku();
        if (empty($sku)) {
            $issues[] = "Missing SKU";
        } else {
            $output->writeln("   ✅ Product Code: {$sku}");
        }
        
        // Product Name
        $name = $product->getName();
        if (empty($name)) {
            $issues[] = "Missing product name";
        } else {
            $output->writeln("   ✅ Product Name: {$name}");
        }
        
        // Product URL
        $url = $product->getProductUrl();
        if (empty($url)) {
            $warnings[] = "Missing product URL";
            $output->writeln("   ⚠️  Product URL: Not set (will use fallback)");
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $warnings[] = "Invalid product URL format";
            $output->writeln("   ⚠️  Product URL: Invalid format - {$url}");
        } else {
            $output->writeln("   ✅ Product URL: {$url}");
        }
        
        // Image URL
        $image = $product->getImage();
        if (empty($image) || $image === 'no_selection') {
            $warnings[] = "Missing product image";
            $output->writeln("   ⚠️  Product Image: Not set (will use placeholder)");
        } else {
            try {
                $imageUrl = $product->getMediaConfig()->getMediaUrl($image);
                if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $output->writeln("   ✅ Image URL: {$imageUrl}");
                } else {
                    $warnings[] = "Invalid image URL format";
                    $output->writeln("   ⚠️  Image URL: Invalid format - {$imageUrl}");
                }
            } catch (\Exception $e) {
                $warnings[] = "Error getting image URL: " . $e->getMessage();
                $output->writeln("   ⚠️  Image URL: Error - {$e->getMessage()}");
            }
        }
        
        // Optional fields
        $gtin = $product->getData('gtin');
        $mpn = $product->getData('mpn');
        $brand = $product->getData('brand');
        
        $output->writeln("   📋 Optional Fields:");
        $output->writeln("      GTIN: " . ($gtin ?: 'Not set'));
        $output->writeln("      MPN: " . ($mpn ?: 'Not set'));
        $output->writeln("      Brand: " . ($brand ?: 'Not set'));
        
        // Show what the API payload would look like
        $locationId = '1080211'; // Test location ID
        $apiData = [
            'location_id' => $locationId,
            'product_code' => $sku,
            'product_name' => $name,
            'source_url' => $url ?: 'https://example.com/product/' . urlencode(strtolower($sku)),
            'image_url' => $image ? $product->getMediaConfig()->getMediaUrl($image) : 'https://via.placeholder.com/300x300.png',
            'active' => true
        ];
        
        if ($sku) $apiData['skus'] = [$sku];
        if ($gtin) $apiData['gtins'] = [$gtin];
        if ($mpn) $apiData['mpns'] = [$mpn];
        if ($brand) $apiData['cluster_code'] = $brand;
        
        $output->writeln("   📤 API Payload:");
        $output->writeln("   " . json_encode($apiData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Summary
        if (!empty($issues)) {
            $output->writeln("   <error>❌ Issues: " . implode(', ', $issues) . "</error>");
        }
        if (!empty($warnings)) {
            $output->writeln("   <comment>⚠️  Warnings: " . implode(', ', $warnings) . "</comment>");
        }
        if (empty($issues) && empty($warnings)) {
            $output->writeln("   <info>✅ Product data looks good!</info>");
        }
    }
}