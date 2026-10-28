<?php

namespace Kiyoh\Reviews\Controller\Adminhtml\Connect;

use Kiyoh\Reviews\Model\Connect\AdminConnectService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Start extends Action implements HttpPostActionInterface
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

    public function __construct(Context $context, JsonFactory $jsonFactory, AdminConnectService $connect)
    {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->connect = $connect;
    }

    public function execute()
    {
        $request = $this->getRequest();
        $result = $this->connect->begin(
            (string) $request->getParam('server', ''),
            (string) $request->getParam('store', ''),
            (string) $request->getParam('website', '')
        );

        return $this->jsonFactory->create()->setData($result);
    }
}
