<?php

namespace Kiyoh\Reviews\Model\Config\Backend;

use Kiyoh\Reviews\Model\Connect\ConnectConfig;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\ValidatorException;
use Kiyoh\Reviews\Api\ApiServiceInterface;

class ValidateCredentials extends Encrypted
{
    protected ApiServiceInterface $apiService;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        ApiServiceInterface $apiService,
        WriterInterface $configWriter,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->apiService = $apiService;
        $this->configWriter = $configWriter;
        parent::__construct($context, $registry, $config, $cacheTypeList, $encryptor, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $enabled = $this->getFieldsetDataValue('enabled');
        
        if (!$enabled) {
            return parent::beforeSave();
        }

        $value = $this->getValue();
        
        if (empty($value)) {
            return parent::beforeSave();
        }
        
        if (preg_match('/^\*+$/', $value)) {
            $this->_dataSaveAllowed = false;
            return $this;
        }

        $server = $this->getFieldsetDataValue('server');
        $locationId = $this->getFieldsetDataValue('location_id');

        if (empty($locationId)) {
            throw new ValidatorException(
                __('Location ID is required.')
            );
        }

        $result = $this->apiService->validateNewApiCredentials($server, $value, $locationId);
        
        if (!$result['success']) {
            throw new ValidatorException(
                __('API validation failed: %1', $result['message'])
            );
        }

        $this->markManualToken();

        return parent::beforeSave();
    }

    /**
     * A pasted token replaces a Kiyoh-Connect session but does not call the merchant's shop.
     * The one-time shop credential is left in place. Connect returns it only once.
     */
    private function markManualToken(): void
    {
        $scope = (string) $this->getScope();
        $scopeId = (int) $this->getScopeId();
        if ($scope === '' || $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $scopeId = 0;
        }

        $this->configWriter->save(ConnectConfig::SOURCE, 'manual', $scope, $scopeId);
        $this->configWriter->delete(ConnectConfig::REFRESH, $scope, $scopeId);
        $this->configWriter->delete(ConnectConfig::ACCESS, $scope, $scopeId);
        $this->configWriter->delete(ConnectConfig::ACCESS_EXPIRES, $scope, $scopeId);
    }
}
