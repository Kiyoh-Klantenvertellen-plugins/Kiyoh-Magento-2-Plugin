<?php

namespace Kiyoh\Reviews\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class BulkSyncButton extends Field
{
    protected $_template = 'Kiyoh_Reviews::system/config/bulk_sync_button.phtml';

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl()
    {
        return $this->getUrl('kiyoh_reviews/productSync/bulkSync');
    }

    public function getButtonHtml()
    {
        $storeId = $this->getRequest()->getParam('store', 0);
        $storeName = 'All Stores';
        
        if ($storeId > 0) {
            try {
                $store = $this->storeManager->getStore($storeId);
                $storeName = $store->getName();
            } catch (\Exception $e) {
                $storeName = 'Store ID: ' . $storeId;
            }
        }

        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData([
            'id' => 'kiyoh_bulk_sync_button',
            'label' => __('Sync Products Now (%1)', $storeName),
            'class' => 'action-default',
            'onclick' => 'kiyohBulkSync.startSync()'
        ]);

        return $button->toHtml();
    }

    public function getCurrentStoreId()
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }
}