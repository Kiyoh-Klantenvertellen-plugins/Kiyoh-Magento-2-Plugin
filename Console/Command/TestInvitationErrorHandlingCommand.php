<?php

namespace Kiyoh\Reviews\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Kiyoh\Reviews\Api\ApiServiceInterface;

class TestInvitationErrorHandlingCommand extends Command
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;
    
    /**
     * @var ApiServiceInterface
     */
    private $apiService;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ApiServiceInterface $apiService,
        string $name = null
    ) {
        $this->orderRepository = $orderRepository;
        $this->apiService = $apiService;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('kiyoh:test:invitation-errors')
            ->setDescription('Test invitation error handling and product sync logic')
            ->addOption(
                'order-id',
                'o',
                InputOption::VALUE_REQUIRED,
                'Order ID to test with'
            )
            ->addOption(
                'product-codes',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated product codes to test with'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orderId = $input->getOption('order-id');
        $productCodesInput = $input->getOption('product-codes');

        if (!$orderId) {
            $output->writeln('<error>Please provide an order ID using --order-id option</error>');
            return Command::FAILURE;
        }

        try {
            $order = $this->orderRepository->get($orderId);
            
            $output->writeln('<info>Testing Invitation Error Handling</info>');
            $output->writeln('=====================================');
            $output->writeln(sprintf('Order ID: %s', $order->getIncrementId()));
            $output->writeln(sprintf('Customer Email: %s', $order->getCustomerEmail()));
            $output->writeln('');

            // Test shop invitation with details
            $output->writeln('<info>Testing Shop Invitation:</info>');
            $shopResult = $this->apiService->sendShopInvitationWithDetails($order);
            
            $output->writeln(sprintf('Success: %s', $shopResult['success'] ? 'Yes' : 'No'));
            if (!$shopResult['success']) {
                $output->writeln(sprintf('Error Code: %s', $shopResult['error_code']));
                $output->writeln(sprintf('Error Message: %s', $shopResult['message']));
            }
            $output->writeln('');

            // Test product invitation with details
            if ($productCodesInput) {
                $productCodes = array_map('trim', explode(',', $productCodesInput));
            } else {
                // Extract from order
                $productCodes = [];
                foreach ($order->getAllVisibleItems() as $item) {
                    $product = $item->getProduct();
                    if ($product && $product->getSku()) {
                        $productCodes[] = $product->getSku();
                    }
                }
            }

            if (!empty($productCodes)) {
                $output->writeln('<info>Testing Product Invitation:</info>');
                $output->writeln(sprintf('Product Codes: %s', implode(', ', $productCodes)));
                
                $productResult = $this->apiService->sendProductInvitationWithDetails($order, $productCodes);
                
                $output->writeln(sprintf('Success: %s', $productResult['success'] ? 'Yes' : 'No'));
                if (!$productResult['success']) {
                    $output->writeln(sprintf('Error Code: %s', $productResult['error_code']));
                    $output->writeln(sprintf('Error Message: %s', $productResult['message']));
                    
                    // Test the sync decision logic
                    $shouldSync = $this->shouldSyncProductsForError($productResult['error_code']);
                    $output->writeln(sprintf('Should Sync Products: %s', $shouldSync ? 'Yes' : 'No'));
                    
                    if ($shouldSync) {
                        $output->writeln('<comment>This error would trigger product sync and retry</comment>');
                    } else {
                        $output->writeln('<comment>This error would NOT trigger product sync</comment>');
                    }
                }
            } else {
                $output->writeln('<comment>No product codes to test with</comment>');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function shouldSyncProductsForError(string $errorCode): bool
    {
        // Same logic as in the observer
        $productSyncErrors = [
            'INVALID_PRODUCT_ID',
            'PRODUCT_NOT_FOUND',
            'UNKNOWN_PRODUCT',
            'MISSING_PRODUCT',
            'PRODUCT_DOES_NOT_EXIST',
            'INVALID_PRODUCT_CODE',
            'PRODUCT_NOT_AVAILABLE'
        ];

        return in_array($errorCode, $productSyncErrors);
    }
}