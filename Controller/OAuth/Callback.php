<?php

namespace Kiyoh\Reviews\Controller\OAuth;

use Kiyoh\Reviews\Model\Connect\AdminConnectService;
use Kiyoh\Reviews\Model\Connect\CallbackGuard;
use Kiyoh\Reviews\Model\Connect\CallbackPage;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Psr\Log\LoggerInterface;

/**
 * Public return address: https://{shop-host}/kiyoh/oauth/callback
 * The state value is the single-use CSRF token created by an admin Connect click.
 */
class Callback extends Action implements HttpGetActionInterface
{
    /**
     * @var RawFactory
     */
    private $rawFactory;

    /**
     * @var AdminConnectService
     */
    private $connect;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        Context $context,
        RawFactory $rawFactory,
        AdminConnectService $connect,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->rawFactory = $rawFactory;
        $this->connect = $connect;
        $this->logger = $logger;
    }

    public function execute()
    {
        $request = $this->getRequest();
        try {
            $host = '';
            $browserReturn = false;
            if (method_exists($request, 'getServer')) {
                $purpose = (string) $request->getServer('HTTP_SEC_PURPOSE');
                if ($purpose === '') {
                    $purpose = (string) $request->getServer('HTTP_PURPOSE');
                }
                $host = (string) $request->getServer('HTTP_HOST');
                $browserReturn = CallbackGuard::isBrowserReturn(
                    (string) $request->getServer('HTTP_SEC_FETCH_DEST'),
                    (string) $request->getServer('HTTP_SEC_FETCH_MODE'),
                    $purpose
                );
            }
            $result = $this->connect->completeCallback(
                (string) $request->getParam('code', ''),
                (string) $request->getParam('state', ''),
                (string) $request->getParam('error', ''),
                $host,
                $browserReturn
            );
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: callback exception', ['exception' => $e->getMessage()]);
            $result = [
                'ok' => false,
                'message' => 'Kiyoh-Connect sign-in could not be finished. Close this window and try again from the Magento admin.',
            ];
        }

        $raw = $this->rawFactory->create();
        $raw->setHeader('Cache-Control', 'no-store', true);
        $raw->setHeader('Pragma', 'no-cache', true);
        $raw->setHeader('Referrer-Policy', 'no-referrer', true);
        $raw->setHeader('X-Robots-Tag', 'noindex', true);
        $raw->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $raw->setContents(CallbackPage::render(!empty($result['ok']), (string) ($result['message'] ?? '')));

        return $raw;
    }
}
