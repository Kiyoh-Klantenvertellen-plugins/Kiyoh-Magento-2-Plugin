<?php

namespace Kiyoh\Reviews\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Kiyoh\Reviews\Observer\SalesOrderSaveAfter;
use Magento\Framework\Event\Observer;
use Magento\Framework\DataObject;

class TestOrderObserverCommand extends Command
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;
    
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;
    
    /**
     * @var SalesOrderSaveAfter
     */
    private $observer;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SalesOrderSaveAfter $observer
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->observer = $observer;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('kiyoh:test:observer')
            ->setDescription('Test Kiyoh order observer with specific order')
            ->addOption(
                'order-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Order ID to test observer with'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $orderId = $input->getOption('order-id');
        
        if (!$orderId) {
            $output->writeln('<error>Please provide --order-id parameter</error>');
            return Command::FAILURE;
        }

        try {
            $order = $this->orderRepository->get($orderId);
            
            $output->writeln('<info>Testing Kiyoh observer with order:</info>');
            $output->writeln("Order ID: {$order->getId()}");
            $output->writeln("Status: {$order->getStatus()}");
            $output->writeln("Customer Email: {$order->getCustomerEmail()}");
            $output->writeln("Store ID: {$order->getStoreId()}");
            $output->writeln('');
            
            $output->writeln('<info>Triggering observer... Check logs for detailed output:</info>');
            $output->writeln('var/log/system.log - for all Kiyoh logs');
            $output->writeln('');
            
            // Create mock observer event
            $event = new DataObject(['order' => $order]);
            $observer = new Observer(['event' => $event]);
            
            // Execute the observer
            $this->observer->execute($observer);
            
            $output->writeln('<info>Observer execution completed. Check the logs above for results.</info>');
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}