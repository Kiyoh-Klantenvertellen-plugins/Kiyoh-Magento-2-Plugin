<?php

namespace Kiyoh\Reviews\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\Exception\ValidatorException;
use Kiyoh\Reviews\Api\ApiServiceInterface;

class ValidateCredentials extends Encrypted
{
    protected ApiServiceInterface $apiService;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        ApiServiceInterface $apiService,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->apiService = $apiService;
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

        return parent::beforeSave();
    }
}
