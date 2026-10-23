<?php

namespace Kiyoh\Reviews\Model\Health;

class HealthReportContext
{
    /**
     * @var int
     */
    public $storeId;

    /**
     * @var string
     */
    public $shopId;

    /**
     * @var string
     */
    public $locationId;

    /**
     * @var string
     */
    public $apiToken;

    /**
     * @var list<string>
     */
    public $mobileBaseUrls;

    /**
     * @var string
     */
    public $pluginVersion;

    /**
     * @param list<string> $mobileBaseUrls
     */
    public function __construct(
        int $storeId,
        string $shopId,
        string $locationId,
        string $apiToken,
        array $mobileBaseUrls,
        string $pluginVersion
    ) {
        $this->storeId = $storeId;
        $this->shopId = $shopId;
        $this->locationId = $locationId;
        $this->apiToken = $apiToken;
        $this->mobileBaseUrls = $mobileBaseUrls;
        $this->pluginVersion = $pluginVersion;
    }
}
