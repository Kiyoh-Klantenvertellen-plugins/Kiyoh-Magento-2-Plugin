<?php

namespace Kiyoh\Reviews\Controller\Adminhtml\Connect;

use Kiyoh\Reviews\Model\Connect\AdminConnectService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Message\ManagerInterface;

class Status extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Kiyoh_Reviews::config';

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var AdminConnectService
     */
    private $connect;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        AdminConnectService $connect,
        ManagerInterface $messageManager
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->connect = $connect;
        $this->messageManager = $messageManager;
    }

    public function execute()
    {
        $request = $this->getRequest();
        $attempt = (string) $request->getParam('attempt', '');
        $result = $this->connect->status(
            $attempt,
            (string) $request->getParam('store', ''),
            (string) $request->getParam('website', '')
        );

        if (!empty($result['done']) && $attempt !== '') {
            $seen = $this->_session->getData('kiyoh_connect_seen');
            if (!is_array($seen)) {
                $seen = [];
            }
            if (!isset($seen[$attempt]) && isset($result['message']) && is_string($result['message'])) {
                $seen[$attempt] = true;
                $this->_session->setData('kiyoh_connect_seen', $seen);
                if (!empty($result['ok'])) {
                    $this->messageManager->addSuccessMessage($result['message']);
                } else {
                    $this->messageManager->addErrorMessage($result['message']);
                }
            }
        }

        return $this->jsonFactory->create()->setData($result);
    }
}
