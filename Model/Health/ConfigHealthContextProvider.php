<?php

namespace Kiyoh\Reviews\Model\Health;

use Kiyoh\Reviews\Model\ModuleMeta;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the store view's Kiyoh credentials and base URL for a health report.
 */
class ConfigHealthContextProvider implements HealthContextProviderInterface
{
    private const PATH_LOCATION_ID = 'kiyoh_reviews/api_settings/location_id';
    private const PATH_API_TOKEN = 'kiyoh_reviews/api_settings/api_token';
    private const PATH_MOBILE_URLS = 'kiyoh_reviews/api_settings/mobile_urls';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    public function get(int $storeId): ?HealthReportContext
    {
        if ($storeId <= 0) {
            return null;
        }

        try {
            $locationId = trim((string) $this->scopeConfig->getValue(
                self::PATH_LOCATION_ID,
                ScopeInterface::SCOPE_STORE,
                $storeId
            ));
            $token = $this->revealToken((string) $this->scopeConfig->getValue(
                self::PATH_API_TOKEN,
                ScopeInterface::SCOPE_STORE,
                $storeId
            ));
            if ($locationId === '' || $token === '') {
                return null;
            }

            $urls = MobileUrls::parse((string) $this->scopeConfig->getValue(
                self::PATH_MOBILE_URLS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            ));
            if ($urls === []) {
                return null;
            }

            $store = $this->storeManager->getStore($storeId);
            $baseUrl = (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
            $shopId = ShopId::fromStoreBaseUrl($baseUrl, (string) $store->getCode());
            if ($shopId === null) {
                $this->logger->warning('Kiyoh health: store has no usable base host', [
                    'storeId' => $storeId,
                ]);
                return null;
            }

            return new HealthReportContext(
                $storeId,
                $shopId,
                $locationId,
                $token,
                $urls,
                ModuleMeta::VERSION
            );
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh health: unable to read store context', [
                'storeId' => $storeId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function listStoreIds(): array
    {
        try {
            $stores = $this->storeManager->getStores();
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh health: unable to list stores', [
                'exception' => $e->getMessage(),
            ]);
            return [];
        }

        $ids = [];
        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            if ($this->get($storeId) !== null) {
                $ids[] = $storeId;
            }
        }

        return $ids;
    }

    public function listStoreIdsForScope(string $websiteCode, string $storeCode): array
    {
        if ($storeCode !== '') {
            try {
                $storeId = (int) $this->storeManager->getStore($storeCode)->getId();
            } catch (\Throwable $e) {
                $this->logger->error('Kiyoh health: unable to resolve store scope', [
                    'storeCode' => $storeCode,
                    'exception' => $e->getMessage(),
                ]);
                return [];
            }

            return $this->get($storeId) !== null ? [$storeId] : [];
        }

        if ($websiteCode !== '') {
            try {
                $stores = $this->storeManager->getWebsite($websiteCode)->getStores();
            } catch (\Throwable $e) {
                $this->logger->error('Kiyoh health: unable to resolve website scope', [
                    'websiteCode' => $websiteCode,
                    'exception' => $e->getMessage(),
                ]);
                return [];
            }

            $ids = [];
            foreach ($stores as $store) {
                $storeId = (int) $store->getId();
                if ($this->get($storeId) !== null) {
                    $ids[] = $storeId;
                }
            }

            return $ids;
        }

        return $this->listStoreIds();
    }

    private function revealToken(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }

        try {
            $revealed = $this->encryptor->decrypt($stored);
            if (is_string($revealed) && $revealed !== '') {
                return $revealed;
            }
        } catch (\Throwable $e) {
            if (preg_match('/^\d+:\d+:/', $stored)) {
                return '';
            }

            return $stored;
        }

        return $stored;
    }
}
