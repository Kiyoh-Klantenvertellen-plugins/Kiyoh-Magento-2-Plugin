<?php

namespace Kiyoh\Reviews\Test\Unit\Invitation;

use Kiyoh\Reviews\Model\Invitation\OrderStatusTransition;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

class OrderStatusTransitionTest extends TestCase
{
    public function testDetectsEntryIntoTriggerStatus(): void
    {
        $order = new OrderTransitionStub('complete', 'processing');

        $this->assertTrue(
            OrderStatusTransition::enteredTriggerStatus($order, ['complete', 'closed'])
        );
    }

    public function testSkipsWhenAlreadyInTriggerStatus(): void
    {
        $order = new OrderTransitionStub('complete', 'complete');

        $this->assertFalse(
            OrderStatusTransition::enteredTriggerStatus($order, ['complete'])
        );
    }

    public function testParsesTriggerCsv(): void
    {
        $this->assertSame(['complete', 'closed'], OrderStatusTransition::parseTriggerStatuses('complete,closed'));
    }
}

class OrderTransitionStub implements OrderInterface
{
  /**
   * @var string
   */
    private $status;

  /**
   * @var string|null
   */
    private $origStatus;

    public function __construct(string $status, ?string $origStatus)
    {
        $this->status = $status;
        $this->origStatus = $origStatus;
    }

    public function getOrigData(string $key)
    {
        if ($key === 'status') {
            return $this->origStatus;
        }

        return null;
    }

    public function getStatus() { return $this->status; }
    public function getEntityId() { return 1; }
    public function setEntityId($entityId) { return $this; }
    public function getIncrementId() { return '1'; }
    public function setIncrementId($id) { return $this; }
    public function getStoreId() { return 1; }
    public function setStoreId($storeId) { return $this; }
    public function getCustomerId() { return null; }
    public function setCustomerId($customerId) { return $this; }
    public function getCustomerEmail() { return 'a@b.com'; }
    public function setCustomerEmail($email) { return $this; }
    public function getCustomerFirstname() { return 'A'; }
    public function setCustomerFirstname($name) { return $this; }
    public function getCustomerLastname() { return 'B'; }
    public function setCustomerLastname($name) { return $this; }
    public function getCustomerGroupId() { return 1; }
    public function setCustomerGroupId($id) { return $this; }
    public function getCustomerIsGuest() { return false; }
    public function setCustomerIsGuest($flag) { return $this; }
    public function getCustomerNote() { return null; }
    public function setCustomerNote($note) { return $this; }
    public function getCustomerNoteNotify() { return null; }
    public function setCustomerNoteNotify($flag) { return $this; }
    public function getBillingAddress() { return null; }
    public function setBillingAddress($address) { return $this; }
    public function getShippingAddress() { return null; }
    public function setShippingAddress($address) { return $this; }
    public function getPayment() { return null; }
    public function setPayment($payment) { return $this; }
    public function getItems() { return []; }
    public function setItems($items) { return $this; }
    public function getStatusHistories() { return []; }
    public function setStatusHistories($histories) { return $this; }
    public function getExtensionAttributes() { return null; }
    public function setExtensionAttributes($attributes) { return $this; }
    public function getState() { return 'complete'; }
    public function setState($state) { return $this; }
    public function setStatus($status) { $this->status = $status; return $this; }
    public function getCouponCode() { return null; }
    public function setCouponCode($code) { return $this; }
    public function getProtectCode() { return null; }
    public function setProtectCode($code) { return $this; }
    public function getShippingDescription() { return null; }
    public function setShippingDescription($desc) { return $this; }
    public function getIsVirtual() { return false; }
    public function setIsVirtual($flag) { return $this; }
    public function getAppliedRuleIds() { return null; }
    public function setAppliedRuleIds($ids) { return $this; }
    public function getId() { return 1; }
}