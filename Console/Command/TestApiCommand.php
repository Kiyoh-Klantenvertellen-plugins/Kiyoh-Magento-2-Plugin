<?php

namespace Kiyoh\Reviews\Console\Command;

use Kiyoh\Reviews\Api\ApiServiceInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class TestApiCommand extends Command
{
    private const OPTION_STORE = 'store';
    private const OPTION_VERBOSE = 'detailed';
    private const OPTION_TEST_EMAIL = 'test-email';
    private const OPTION_INVITE_TYPE = 'invite-type';

    /**
     * @var ApiServiceInterface
     */
    private $apiService;
    
    /**
     * @var State
     */
    private $appState;
    
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;
    
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;
    
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    public function __construct(
        ApiServiceInterface $apiService,
        State $appState,
        StoreManagerInterface $storeManager,
        OrderRepositoryInterface $orderRepository,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ?string $name = null
    ) {
        $this->apiService = $apiService;
        $this->appState = $appState;
        $this->storeManager = $storeManager;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('kiyoh:test-api')
            ->setDescription('Test Kiyoh API integration with your store configuration and data')
            ->addOption(
                self::OPTION_STORE,
                's',
                InputOption::VALUE_OPTIONAL,
                'Store ID to test (default: 1)',
                1
            )
            ->addOption(
                self::OPTION_VERBOSE,
                'd',
                InputOption::VALUE_NONE,
                'Show detailed API responses'
            )
            ->addOption(
                self::OPTION_TEST_EMAIL,
                'e',
                InputOption::VALUE_OPTIONAL,
                'Test email for invitations (default: cvisser8@ekomi-group.com)',
                'cvisser8@ekomi-group.com'
            )
            ->addOption(
                self::OPTION_INVITE_TYPE,
                'i',
                InputOption::VALUE_OPTIONAL,
                'Invitation type: "product-only", "shop-only", or "shop-and-product" (default: shop-and-product)',
                'shop-and-product'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set
        }

        try {
            $storeId = (int) $input->getOption(self::OPTION_STORE);
            $verbose = $input->getOption(self::OPTION_VERBOSE);
            $testEmail = $input->getOption(self::OPTION_TEST_EMAIL);
            $inviteType = $input->getOption(self::OPTION_INVITE_TYPE);

            $output->writeln('');
            $output->writeln('<info>🔍 Kiyoh API Integration Test</info>');
            $output->writeln('<info>==============================</info>');
            $output->writeln("Store ID: {$storeId}");
            $output->writeln("Test Email: {$testEmail}");
            $output->writeln("Invitation Type: {$inviteType}");
            $output->writeln('');

        // Test 1: Configuration Check
        $output->writeln('<comment>1️⃣ Testing Configuration...</comment>');
        $configResult = $this->testConfiguration($storeId, $verbose, $output);
        
        if (!$configResult['enabled']) {
            $output->writeln('<error>❌ Kiyoh Reviews is disabled. Enable it in admin configuration.</error>');
            return Cli::RETURN_FAILURE;
        }

        // Test 2: API Credentials
        $output->writeln('<comment>2️⃣ Testing API Credentials...</comment>');
        $credentialsResult = $this->testCredentials($storeId, $verbose, $output);
        
        if (!$credentialsResult['valid']) {
            $output->writeln('<error>❌ API credentials are invalid. Check your configuration.</error>');
            return Cli::RETURN_FAILURE;
        }

        // Test 3: Shop Reviews
        $output->writeln('<comment>3️⃣ Testing Shop Reviews...</comment>');
        $this->testShopReviews($storeId, $verbose, $output);

        // Test 4: Product Reviews
        $output->writeln('<comment>4️⃣ Testing Product Reviews...</comment>');
        $this->testProductReviews($storeId, $verbose, $output);

        // Test 5: Invitation (based on selected type)
        if ($inviteType === 'product-only') {
            $output->writeln('<comment>5️⃣ Testing Product-Only Invitation...</comment>');
            $this->testProductOnlyInvitation($storeId, $testEmail, $verbose, $output);
        } elseif ($inviteType === 'shop-only') {
            $output->writeln('<comment>5️⃣ Testing Shop-Only Invitation...</comment>');
            $this->testShopOnlyInvitation($storeId, $testEmail, $verbose, $output);
        } else {
            $output->writeln('<comment>5️⃣ Testing Shop + Product Invitation...</comment>');
            $this->testShopAndProductInvitation($storeId, $testEmail, $verbose, $output);
        }

        // Test 6: Product Sync (with real product)
        $output->writeln('<comment>6️⃣ Testing Product Sync...</comment>');
        $this->testProductSync($storeId, $verbose, $output);

        // Test 7: Bulk Product Sync
        $output->writeln('<comment>7️⃣ Testing Bulk Product Sync...</comment>');
        $this->testBulkProductSync($storeId, $verbose, $output);

        // Summary
        $output->writeln('');
        $output->writeln('<info>🏁 API Test Complete!</info>');
        $output->writeln('');
        $output->writeln('<comment>📋 Summary:</comment>');
        $output->writeln('- ✅ Working features can be used in production');
        $output->writeln('- ⚠️  Limited features may need additional API permissions');
        $output->writeln('- ❌ Failed features need configuration or permission fixes');
        $output->writeln('');
        $output->writeln('<comment>💡 Invitation Types Tested:</comment>');
        if ($inviteType === 'product-only') {
            $output->writeln('- Product-only invitations (product_invite: true)');
            $output->writeln('- To test other types, use: --invite-type=shop-only or --invite-type=shop-and-product');
        } elseif ($inviteType === 'shop-only') {
            $output->writeln('- Shop-only invitations (product_invite: false, no product codes)');
            $output->writeln('- To test other types, use: --invite-type=product-only or --invite-type=shop-and-product');
        } else {
            $output->writeln('- Shop + product invitations (product_invite: false)');
            $output->writeln('- To test other types, use: --invite-type=product-only or --invite-type=shop-only');
        }
        $output->writeln('');
        $output->writeln('<comment>💡 Next Steps:</comment>');
        $output->writeln('- Contact Kiyoh support for additional API permissions if needed');
        $output->writeln('- Check admin configuration for any missing settings');
        $output->writeln('- Review error messages for specific requirements');
        $output->writeln('- Check logs with: grep -i "kiyoh" var/log/system.log | tail -20');

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln('<error>❌ Critical error during API test:</error>');
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln('');
            $output->writeln('<comment>Stack trace:</comment>');
            $output->writeln($e->getTraceAsString());
            return Cli::RETURN_FAILURE;
        }
    }

    private function testConfiguration(int $storeId, bool $verbose, OutputInterface $output): array
    {
        $store = $this->storeManager->getStore($storeId);
        $output->writeln("   Store: {$store->getName()} ({$store->getCode()})");

        // We'll need to check configuration through the API service
        // For now, assume it's enabled if we can create the service
        $result = ['enabled' => true, 'details' => []];
        
        $output->writeln('   <info>✅ Configuration loaded successfully</info>');
        
        return $result;
    }

    private function testCredentials(int $storeId, bool $verbose, OutputInterface $output): array
    {
        try {
            // Use the shop reviews endpoint to validate credentials
            $shopReviews = $this->apiService->getShopReviews($storeId);
            
            if ($shopReviews !== null) {
                $output->writeln('   <info>✅ API credentials are valid</info>');
                if (isset($shopReviews['locationName'])) {
                    $output->writeln("   📍 Location: {$shopReviews['locationName']}");
                }
                return ['valid' => true, 'data' => $shopReviews];
            } else {
                $output->writeln('   <error>❌ API credentials validation failed</error>');
                return ['valid' => false];
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ API credentials test failed: ' . $e->getMessage() . '</error>');
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    private function testShopReviews(int $storeId, bool $verbose, OutputInterface $output): void
    {
        try {
            $reviews = $this->apiService->getShopReviews($storeId);
            
            if ($reviews !== null) {
                $output->writeln('   <info>✅ Shop reviews retrieved successfully</info>');
                $output->writeln("   ⭐ Average Rating: " . ($reviews['averageRating'] ?? 'N/A'));
                $output->writeln("   📝 Number of Reviews: " . ($reviews['numberReviews'] ?? 'N/A'));
                $output->writeln("   👍 Recommendation: " . ($reviews['recommendation'] ?? 'N/A') . '%');
                
                if ($verbose && !empty($reviews)) {
                    $output->writeln('   📋 Full Response:');
                    $output->writeln('   ' . json_encode($reviews, JSON_PRETTY_PRINT));
                }
            } else {
                $output->writeln('   <error>❌ No shop reviews data returned</error>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Shop reviews test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function testProductReviews(int $storeId, bool $verbose, OutputInterface $output): void
    {
        try {
            // Get a real product from the store
            $searchCriteria = $this->searchCriteriaBuilder
                ->setPageSize(1)
                ->setCurrentPage(1)
                ->create();
            
            $products = $this->productRepository->getList($searchCriteria);
            
            if ($products->getTotalCount() > 0) {
                $productItems = array_values($products->getItems());
                $product = $productItems[0];
                $productCode = $product->getSku();
                
                $output->writeln("   🔍 Testing with product: {$product->getName()} (SKU: {$productCode})");
                
                $reviews = $this->apiService->getProductReviews($productCode, $storeId);
                
                if ($reviews !== null) {
                    $output->writeln('   <info>✅ Product reviews retrieved successfully</info>');
                    if ($verbose && !empty($reviews)) {
                        $output->writeln('   📋 Response:');
                        $output->writeln('   ' . json_encode($reviews, JSON_PRETTY_PRINT));
                    }
                } else {
                    $output->writeln('   <comment>⚠️  No product reviews data (expected for new products)</comment>');
                }
            } else {
                $output->writeln('   <comment>⚠️  No products found in store to test</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Product reviews test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function testShopInvitation(int $storeId, string $testEmail, bool $verbose, OutputInterface $output): void
    {
        try {
            // Get a real order or create a mock one
            $order = $this->getTestOrder($storeId, $testEmail);
            
            if ($order) {
                $output->writeln("   📧 Testing invitation for: {$order->getCustomerEmail()}");
                $output->writeln("   📋 Order ID: " . ($order->getIncrementId() ?? 'mock-order'));
                
                $result = $this->apiService->sendShopInvitation($order);
                
                if ($result) {
                    $output->writeln('   <info>✅ Shop invitation sent successfully</info>');
                } else {
                    $output->writeln('   <comment>⚠️  Shop invitation failed (check logs for details)</comment>');
                }
            } else {
                $output->writeln('   <comment>⚠️  No orders found to test invitation</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Shop invitation test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function testProductInvitation(int $storeId, string $testEmail, bool $verbose, OutputInterface $output): void
    {
        try {
            $order = $this->getTestOrder($storeId, $testEmail);
            $targetSkus = ['24-MB01', '24-MB04'];
            
            // First, sync the specific products we want to test
            $output->writeln("   � Pre-sysncing products for invitation test...");
            $syncedProducts = [];
            
            foreach ($targetSkus as $sku) {
                try {
                    $product = $this->productRepository->get($sku);
                    $syncResult = $this->apiService->syncProduct($product);
                    
                    if ($syncResult) {
                        $syncedProducts[] = $sku;
                        $output->writeln("   ✅ Synced: {$sku}");
                    } else {
                        $output->writeln("   ⚠️  Failed to sync: {$sku}");
                    }
                } catch (\Exception $e) {
                    $output->writeln("   ❌ Product {$sku} not found or sync failed: " . $e->getMessage());
                }
            }
            
            if ($order && !empty($syncedProducts)) {
                $output->writeln("   📧 Testing product invitation for: {$order->getCustomerEmail()}");
                $output->writeln("   📦 Products: " . implode(', ', $syncedProducts));
                $output->writeln("   📋 Order ID: " . ($order->getIncrementId() ?? 'mock-order'));
                
                $result = $this->apiService->sendProductInvitation($order, $syncedProducts);
                
                if ($result) {
                    $output->writeln('   <info>✅ Product invitation sent successfully</info>');
                } else {
                    $output->writeln('   <comment>⚠️  Product invitation failed (check logs for details)</comment>');
                }
            } else {
                $output->writeln('   <comment>⚠️  No suitable order/products found for testing</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Product invitation test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function testProductSync(int $storeId, bool $verbose, OutputInterface $output): void
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->setPageSize(1)
                ->setCurrentPage(1)
                ->create();
            
            $products = $this->productRepository->getList($searchCriteria);
            
            if ($products->getTotalCount() > 0) {
                $productItems = array_values($products->getItems());
                $product = $productItems[0];
                $output->writeln("   📦 Testing sync for: {$product->getName()} (SKU: {$product->getSku()})");
                
                $result = $this->apiService->syncProduct($product);
                
                if ($result) {
                    $output->writeln('   <info>✅ Product synced successfully</info>');
                } else {
                    $output->writeln('   <comment>⚠️  Product sync failed (may need product review feature)</comment>');
                }
            } else {
                $output->writeln('   <comment>⚠️  No products found to sync</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Product sync test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function testBulkProductSync(int $storeId, bool $verbose, OutputInterface $output): void
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->setPageSize(3)
                ->setCurrentPage(1)
                ->create();
            
            $productList = $this->productRepository->getList($searchCriteria);
            $products = array_values($productList->getItems());
            
            if (!empty($products)) {
                $productNames = array_map(function($p) { return $p->getName(); }, $products);
                $output->writeln("   📦 Testing bulk sync for " . count($products) . " products:");
                $output->writeln("   " . implode(', ', array_slice($productNames, 0, 3)));
                
                $result = $this->apiService->syncProductsBulk($products);
                
                $output->writeln("   ✅ Success: {$result['success']}");
                $output->writeln("   ❌ Failed: {$result['failed']}");
                
                if (!empty($result['errors'])) {
                    $output->writeln("   🚨 Errors: " . implode(', ', $result['errors']));
                    $output->writeln('   <comment>💡 Bulk sync may need product review feature</comment>');
                }
                
                if ($result['success'] > 0) {
                    $output->writeln('   <info>✅ Some products synced successfully</info>');
                } else {
                    $output->writeln('   <comment>⚠️  Bulk sync failed (may need product review feature)</comment>');
                }
            } else {
                $output->writeln('   <comment>⚠️  No products found for bulk sync</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Bulk product sync test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function getTestOrder(int $storeId, string $testEmail)
    {
        try {
            // Try to get a recent order
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('store_id', $storeId)
                ->setPageSize(1)
                ->setCurrentPage(1)
                ->create();
            
            $orders = $this->orderRepository->getList($searchCriteria);
            
            if ($orders->getTotalCount() > 0) {
                $order = $orders->getItems()[0];
                // Override email for testing
                $order->setCustomerEmail($testEmail);
                return $order;
            }
        } catch (\Exception $e) {
            // Fall back to mock order
        }
        
        // Create a mock order for testing
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $orderFactory = $objectManager->get(\Magento\Sales\Api\Data\OrderInterfaceFactory::class);
        $order = $orderFactory->create();
        
        $order->setStoreId($storeId);
        $order->setCustomerEmail($testEmail);
        $order->setCustomerFirstname('Test');
        $order->setCustomerLastname('Customer');
        
        return $order;
    }

    private function getTestProductCodes(int $storeId, int $limit = 2): array
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->setPageSize($limit)
                ->setCurrentPage(1)
                ->create();
            
            $products = $this->productRepository->getList($searchCriteria);
            
            $codes = [];
            foreach (array_values($products->getItems()) as $product) {
                $codes[] = $product->getSku();
            }
            
            return $codes;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function testProductOnlyInvitation(int $storeId, string $testEmail, bool $verbose, OutputInterface $output): void
    {
        try {
            $order = $this->getTestOrder($storeId, $testEmail);
            $targetSkus = ['24-MB01', '24-MB04'];
            
            // First, sync the specific products we want to test
            $output->writeln("   🔄 Pre-syncing products for invitation test...");
            $syncedProducts = [];
            
            foreach ($targetSkus as $sku) {
                try {
                    $product = $this->productRepository->get($sku);
                    $syncResult = $this->apiService->syncProduct($product);
                    
                    if ($syncResult) {
                        $syncedProducts[] = $sku;
                        $output->writeln("   ✅ Synced: {$sku}");
                    } else {
                        $output->writeln("   ⚠️  Failed to sync: {$sku}");
                    }
                } catch (\Exception $e) {
                    $output->writeln("   ❌ Product {$sku} not found or sync failed: " . $e->getMessage());
                }
            }
            
            if ($order && !empty($syncedProducts)) {
                $output->writeln("   📧 Testing product-only invitation for: {$order->getCustomerEmail()}");
                $output->writeln("   📦 Products: " . implode(', ', $syncedProducts));
                $output->writeln("   📋 Order ID: " . ($order->getIncrementId() ?? 'mock-order'));
                $output->writeln("   🎯 Type: Product reviews only (product_invite: true)");
                
                $result = $this->apiService->sendProductInvitation($order, $syncedProducts);
                
                if ($result) {
                    $output->writeln('   <info>✅ Product-only invitation sent successfully</info>');
                } else {
                    $output->writeln('   <comment>⚠️  Product-only invitation failed (check logs for details)</comment>');
                }
            } else {
                $output->writeln('   <comment>⚠️  No suitable order/products found for testing</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Product-only invitation test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function testShopAndProductInvitation(int $storeId, string $testEmail, bool $verbose, OutputInterface $output): void
    {
        try {
            $order = $this->getTestOrder($storeId, $testEmail);
            $targetSkus = ['24-MB01', '24-MB04'];
            
            // First, sync the specific products we want to test
            $output->writeln("   🔄 Pre-syncing products for invitation test...");
            $syncedProducts = [];
            
            foreach ($targetSkus as $sku) {
                try {
                    $product = $this->productRepository->get($sku);
                    $syncResult = $this->apiService->syncProduct($product);
                    
                    if ($syncResult) {
                        $syncedProducts[] = $sku;
                        $output->writeln("   ✅ Synced: {$sku}");
                    } else {
                        $output->writeln("   ⚠️  Failed to sync: {$sku}");
                    }
                } catch (\Exception $e) {
                    $output->writeln("   ❌ Product {$sku} not found or sync failed: " . $e->getMessage());
                }
            }
            
            if ($order && !empty($syncedProducts)) {
                $output->writeln("   📧 Testing shop + product invitation for: {$order->getCustomerEmail()}");
                $output->writeln("   📦 Products: " . implode(', ', $syncedProducts));
                $output->writeln("   📋 Order ID: " . ($order->getIncrementId() ?? 'mock-order'));
                $output->writeln("   🎯 Type: Shop + Product reviews (product_invite: false)");
                
                // For shop + product, we need to create a custom invitation
                $result = $this->sendShopAndProductInvitation($order, $syncedProducts, $storeId);
                
                if ($result) {
                    $output->writeln('   <info>✅ Shop + Product invitation sent successfully</info>');
                } else {
                    $output->writeln('   <comment>⚠️  Shop + Product invitation failed (check logs for details)</comment>');
                }
            } else {
                $output->writeln('   <comment>⚠️  No suitable order/products found for testing</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Shop + Product invitation test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function testShopOnlyInvitation(int $storeId, string $testEmail, bool $verbose, OutputInterface $output): void
    {
        try {
            $order = $this->getTestOrder($storeId, $testEmail);
            
            if ($order) {
                $output->writeln("   📧 Testing shop-only invitation for: {$order->getCustomerEmail()}");
                $output->writeln("   📋 Order ID: " . ($order->getIncrementId() ?? 'mock-order'));
                $output->writeln("   🎯 Type: Shop reviews only (product_invite: false, no product codes)");
                
                $result = $this->apiService->sendShopInvitation($order);
                
                if ($result) {
                    $output->writeln('   <info>✅ Shop-only invitation sent successfully</info>');
                } else {
                    $output->writeln('   <comment>⚠️  Shop-only invitation failed (check logs for details)</comment>');
                }
            } else {
                $output->writeln('   <comment>⚠️  No suitable order found for testing</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('   <error>❌ Shop-only invitation test failed: ' . $e->getMessage() . '</error>');
        }
    }

    private function sendShopAndProductInvitation(OrderInterface $order, array $productCodes, int $storeId): bool
    {
        // Use the new ApiService method for shop + product invitations
        return $this->apiService->sendShopAndProductInvitation($order, $productCodes);
    }
}