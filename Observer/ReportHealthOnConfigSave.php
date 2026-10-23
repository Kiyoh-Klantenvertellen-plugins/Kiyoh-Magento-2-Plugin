<?php

namespace Kiyoh\Reviews\Observer;

use Kiyoh\Reviews\Model\Health\HealthReporter;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ReportHealthOnConfigSave implements ObserverInterface
{
    /**
     * @var HealthReporter
     */
    private $healthReporter;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(HealthReporter $healthReporter, LoggerInterface $logger)
    {
        $this->healthReporter = $healthReporter;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $website = (string) $observer->getEvent()->getData('website');
            $store = (string) $observer->getEvent()->getData('store');
            $this->healthReporter->noteConfigSaved($website, $store);
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh health: config save report failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
