<?php

namespace Kiyoh\Reviews\Cron;

use Kiyoh\Reviews\Model\Invitation\OrderInvitationProcessor;
use Kiyoh\Reviews\Model\ResourceModel\OrderInviteRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class RetryFailedInvitations
{
    private const CONFIG_PATH_INVITATIONS_ENABLED = 'kiyoh_reviews/review_invitations/enabled';

    /**
     * @var OrderInviteRepository
     */
    private $inviteRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderInvitationProcessor
     */
    private $invitationProcessor;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        OrderInviteRepository $inviteRepository,
        OrderRepositoryInterface $orderRepository,
        OrderInvitationProcessor $invitationProcessor,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->inviteRepository = $inviteRepository;
        $this->orderRepository = $orderRepository;
        $this->invitationProcessor = $invitationProcessor;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        $due = $this->inviteRepository->listDueForRetry();
        if ($due === []) {
            return;
        }

        foreach ($due as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            $storeId = (int) ($row['store_id'] ?? 0);
            $attemptCount = (int) ($row['attempt_count'] ?? 0);

            if ($orderId <= 0) {
                continue;
            }

            if (!$this->isInvitationsEnabled($storeId)) {
                continue;
            }

            try {
                $order = $this->orderRepository->get($orderId);
            } catch (\Exception $e) {
                $this->logger->warning('Kiyoh Reviews: Cron retry could not load order', [
                    'order_id' => $orderId,
                    'exception' => $e->getMessage(),
                ]);
                continue;
            }

            $nextAttempt = $attemptCount + 1;
            $this->logger->info('Kiyoh Reviews: Cron retrying failed invitation', [
                'order_id' => $orderId,
                'attempt' => $nextAttempt,
            ]);

            $this->invitationProcessor->sendInvitations($order, $storeId, $nextAttempt);
        }
    }

    private function isInvitationsEnabled(int $storeId): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::CONFIG_PATH_INVITATIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}