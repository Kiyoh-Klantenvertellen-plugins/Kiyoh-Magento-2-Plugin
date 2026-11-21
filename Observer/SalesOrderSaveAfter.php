<?php

namespace Kiyoh\Reviews\Observer;

use Kiyoh\Reviews\Helper\LogPrivacy;
use Kiyoh\Reviews\Model\Invitation\OrderInvitationProcessor;
use Kiyoh\Reviews\Model\Invitation\OrderStatusTransition;
use Kiyoh\Reviews\Model\ResourceModel\OrderInviteRepository;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class SalesOrderSaveAfter implements ObserverInterface
{
    private const CONFIG_PATH_INVITATIONS_ENABLED = 'kiyoh_reviews/review_invitations/enabled';
    private const CONFIG_PATH_ORDER_STATUS_TRIGGER = 'kiyoh_reviews/review_invitations/order_status_trigger';
    private const CONFIG_PATH_EXCLUDE_CUSTOMER_GROUPS = 'kiyoh_reviews/review_invitations/exclude_customer_groups';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var OrderInviteRepository
     */
    private $inviteRepository;

    /**
     * @var OrderInvitationProcessor
     */
    private $invitationProcessor;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        OrderInviteRepository $inviteRepository,
        OrderInvitationProcessor $invitationProcessor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->inviteRepository = $inviteRepository;
        $this->invitationProcessor = $invitationProcessor;
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();

        if (!$order instanceof OrderInterface) {
            $this->logger->debug('Kiyoh Reviews Observer: Event object is not an OrderInterface');
            return;
        }

        $storeId = (int) $order->getStoreId();
        $orderId = (int) $order->getId();
        $orderStatus = $order->getStatus();

        $this->logger->info('Kiyoh Reviews Observer: Order save event triggered', [
            'order_id' => $orderId,
            'order_status' => $orderStatus,
            'customer_email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
            'store_id' => $storeId,
            'event_time' => date('Y-m-d H:i:s'),
        ]);

        if (!$this->shouldProcessOrder($order, $storeId)) {
            $this->logger->info('Kiyoh Reviews Observer: Order processing skipped', [
                'order_id' => $orderId,
                'reason' => 'Failed shouldProcessOrder validation',
            ]);
            return;
        }

        $this->logger->info('Kiyoh Reviews Observer: Starting invitation processing', [
            'order_id' => $orderId,
            'customer_email' => LogPrivacy::maskEmail($order->getCustomerEmail()),
        ]);

        $this->invitationProcessor->sendInvitations($order, $storeId, 1);
    }

    private function shouldProcessOrder(OrderInterface $order, int $storeId): bool
    {
        $orderId = (int) $order->getId();

        if (!$this->isInvitationsEnabled($storeId)) {
            $this->logger->info('Kiyoh Reviews Observer: Review invitations disabled', [
                'order_id' => $orderId,
            ]);
            return false;
        }

        $triggerStatuses = OrderStatusTransition::parseTriggerStatuses(
            (string) $this->getConfig(self::CONFIG_PATH_ORDER_STATUS_TRIGGER, $storeId)
        );
        if (!OrderStatusTransition::enteredTriggerStatus($order, $triggerStatuses)) {
            $this->logger->info('Kiyoh Reviews Observer: Order status did not enter a trigger status', [
                'order_id' => $orderId,
                'current_status' => $order->getStatus(),
                'trigger_statuses' => $triggerStatuses,
            ]);
            return false;
        }

        if ($this->inviteRepository->shouldSkipSending($orderId)) {
            $this->logger->info('Kiyoh Reviews Observer: Invitation already sent or exhausted retries', [
                'order_id' => $orderId,
            ]);
            return false;
        }

        if ($this->isCustomerGroupExcluded($order, $storeId)) {
            $this->logger->info('Kiyoh Reviews Observer: Customer group excluded', [
                'order_id' => $orderId,
                'customer_group_id' => $order->getCustomerGroupId(),
                'excluded_groups' => $this->getConfig(self::CONFIG_PATH_EXCLUDE_CUSTOMER_GROUPS, $storeId),
            ]);
            return false;
        }

        if (!$order->getCustomerEmail()) {
            $this->logger->warning('Kiyoh Reviews Observer: Order has no customer email', [
                'order_id' => $orderId,
            ]);
            return false;
        }

        $this->logger->info('Kiyoh Reviews Observer: Order validation passed', [
            'order_id' => $orderId,
            'ready_for_processing' => true,
        ]);

        return true;
    }

    private function isCustomerGroupExcluded(OrderInterface $order, int $storeId): bool
    {
        $excludedGroups = $this->getConfig(self::CONFIG_PATH_EXCLUDE_CUSTOMER_GROUPS, $storeId);

        if (!$excludedGroups) {
            return false;
        }

        $excludedGroupArray = explode(',', $excludedGroups);
        $customerGroupId = $order->getCustomerGroupId();

        return in_array($customerGroupId, $excludedGroupArray);
    }

    private function isInvitationsEnabled(int $storeId): bool
    {
        return (bool) $this->getConfig(self::CONFIG_PATH_INVITATIONS_ENABLED, $storeId);
    }

    private function getConfig(string $path, int $storeId)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}