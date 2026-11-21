<?php

namespace Magento\Sales\Api\Data;

/**
 * Minimal stub for unit tests without a full Magento install.
 */
interface OrderInterface
{
    public function getEntityId();
    public function setEntityId($entityId);
    public function getIncrementId();
    public function setIncrementId($id);
    public function getStoreId();
    public function setStoreId($storeId);
    public function getCustomerId();
    public function setCustomerId($customerId);
    public function getCustomerEmail();
    public function setCustomerEmail($email);
    public function getCustomerFirstname();
    public function setCustomerFirstname($name);
    public function getCustomerLastname();
    public function setCustomerLastname($name);
    public function getCustomerGroupId();
    public function setCustomerGroupId($id);
    public function getCustomerIsGuest();
    public function setCustomerIsGuest($flag);
    public function getCustomerNote();
    public function setCustomerNote($note);
    public function getCustomerNoteNotify();
    public function setCustomerNoteNotify($flag);
    public function getBillingAddress();
    public function setBillingAddress($address);
    public function getShippingAddress();
    public function setShippingAddress($address);
    public function getPayment();
    public function setPayment($payment);
    public function getItems();
    public function setItems($items);
    public function getStatusHistories();
    public function setStatusHistories($histories);
    public function getExtensionAttributes();
    public function setExtensionAttributes($attributes);
    public function getState();
    public function setState($state);
    public function getStatus();
    public function setStatus($status);
    public function getCouponCode();
    public function setCouponCode($code);
    public function getProtectCode();
    public function setProtectCode($code);
    public function getShippingDescription();
    public function setShippingDescription($desc);
    public function getIsVirtual();
    public function setIsVirtual($flag);
    public function getAppliedRuleIds();
    public function setAppliedRuleIds($ids);
}