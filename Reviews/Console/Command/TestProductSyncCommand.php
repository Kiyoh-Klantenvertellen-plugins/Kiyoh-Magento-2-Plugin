<?php

namespace Kiyoh\Reviews\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Kiyoh\Reviews\Service\ProductSyncService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class TestProductSyncCommand extends Command
{
    /**
     * @var ProductSyncService
     */
    private $productSyncService;
    
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;
    
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    public function __construct(
        ProductSyncService $productSyncService,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        string $name = null
    ) {
        $this->productSyncService = $productSyncService;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('kiyoh:product:test-sync')
            ->setDescription('Test product sync functionality with a single product')
            ->addOption(
                'sku',
                null,
                InputOption::VALUE_REQUIRED,
                'Product SKU to test sync with'
            )
            ->addOption(
                'store-id',
                's',
                InputOption::VALUE_OPTIONAL,
                'Store ID to test with',
                0
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sku = $input->getOption('sku');
        $storeId = (int) $input->getOption('store-id');

        if (!$sku) {
            $output->writeln('<error>Please provide a product SKU using --sku option</error>');
            return Command::FAILURE;
        }

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
            
            $output->writeln(sprintf('<info>Testing product sync for SKU: %s</info>', $sku));
            $output->writeln(sprintf('Product Name: %s', $product->getName()));
            $output->writeln(sprintf('Product Type: %s', $product->getTypeId()));
            $output->writeln(sprintf('Store ID: %d', $storeId));

            $shouldSync = $this->productSyncService->shouldSyncProduct($product, $storeId);
            $output->writeln(sprintf('Should sync: %s', $shouldSync ? 'Yes' : 'No'));

            if (!$shouldSync) {
                $output->writeln('<comment>Product is excluded from sync based on configuration</comment>');
                return Command::SUCCESS;
            }

            $output->writeln('<info>Attempting to sync product...</info>');
            $result = $this->productSyncService->syncProduct($product, $storeId);

            if ($result) {
                $output->writeln('<info>Product sync successful!</info>');
                return Command::SUCCESS;
            } else {
                $output->writeln('<error>Product sync failed</error>');
                return Command::FAILURE;
            }

        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $output->writeln(sprintf('<error>Product with SKU "%s" not found</error>', $sku));
            return Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}