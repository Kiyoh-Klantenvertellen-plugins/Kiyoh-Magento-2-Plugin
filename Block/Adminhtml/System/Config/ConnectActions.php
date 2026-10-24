<?php

namespace Kiyoh\Reviews\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ConnectActions extends Field
{
    protected $_template = 'Kiyoh_Reviews::system/config/connect_actions.phtml';

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getStartUrl(): string
    {
        return $this->getUrl('kiyoh_reviews/connect/start');
    }

    public function getStatusUrl(): string
    {
        return $this->getUrl('kiyoh_reviews/connect/status');
    }

    public function getDisconnectUrl(): string
    {
        return $this->getUrl('kiyoh_reviews/connect/disconnect');
    }

    public function getTestUrl(): string
    {
        return $this->getUrl('kiyoh_reviews/connect/test');
    }

    public function getStoreParam(): string
    {
        return (string) $this->getRequest()->getParam('store', '');
    }

    public function getWebsiteParam(): string
    {
        return (string) $this->getRequest()->getParam('website', '');
    }
}
