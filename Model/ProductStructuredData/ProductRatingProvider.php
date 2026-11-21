<?php

namespace Kiyoh\Reviews\Model\ProductStructuredData;

use Kiyoh\Reviews\Api\ApiServiceInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

class ProductRatingProvider
{
    private const CACHE_TAG = 'kiyoh_product_rating';
    private const CACHE_LIFETIME = 43200; // 12 hours
    private const CONFIG_PATH_ENABLED = 'kiyoh_reviews/product_structured_data/enabled';

    /**
     * @var ApiServiceInterface
     */
    private $apiService;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(
        ApiServiceInterface $apiService,
        CacheInterface $cache,
        Json $json,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->apiService = $apiService;
        $this->cache = $cache;
        $this->json = $json;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @return array{ratingValue: float, reviewCount: int, bestRating: int, worstRating: int}|null
     */
    public function getAggregate(string $productCode, int $storeId): ?array
    {
        if (!$this->isEnabled($storeId) || $productCode === '') {
            return null;
        }

        $cacheKey = self::CACHE_TAG . '_' . $storeId . '_' . sha1($productCode);
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                $decoded = $this->json->unserialize($cached);
                if (is_array($decoded) && isset($decoded['ratingValue'])) {
                    return $decoded;
                }
                if ($cached === 'null') {
                    return null;
                }
            } catch (\InvalidArgumentException $e) {
                // fall through to API
            }
        }

        $payload = $this->apiService->getProductReviews($productCode, $storeId);
        $aggregate = ProductReviewAggregate::fromApiPayload($payload);

        $cacheValue = $aggregate === null ? 'null' : $this->json->serialize($aggregate);
        $this->cache->save($cacheValue, $cacheKey, [self::CACHE_TAG], self::CACHE_LIFETIME);

        return $aggregate;
    }

    public function isEnabled(int $storeId): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::CONFIG_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}