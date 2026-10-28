<?php

namespace Kiyoh\Reviews\Cron;

use Kiyoh\Reviews\Model\Health\HealthReporter;
use Psr\Log\LoggerInterface;

class ReportMobileHealth
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

    public function execute(): void
    {
        try {
            $this->healthReporter->publishDueHeartbeats();
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh health: daily report failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
