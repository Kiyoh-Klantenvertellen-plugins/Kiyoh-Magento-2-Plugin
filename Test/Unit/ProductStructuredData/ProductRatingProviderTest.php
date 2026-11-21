<?php

namespace Kiyoh\Reviews\Test\Unit\ProductStructuredData;

use Kiyoh\Reviews\Api\ApiServiceInterface;
use Kiyoh\Reviews\Model\ProductStructuredData\ProductRatingProvider;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class ProductRatingProviderTest extends TestCase
{
    public function testReturnsNullWhenDisabled(): void
    {
        $provider = new ProductRatingProvider(
            $this->createMock(ApiServiceInterface::class),
            $this->createMock(CacheInterface::class),
            new Json(),
            $this->configReturning('0')
        );

        $this->assertNull($provider->getAggregate('SKU-1', 1));
    }

    public function testCachesSuccessfulAggregate(): void
    {
        $api = $this->createMock(ApiServiceInterface::class);
        $api->expects($this->once())
            ->method('getProductReviews')
            ->with('SKU-1', 1)
            ->willReturn(['averageRating' => 9.2, 'numberReviews' => 3]);

        $cache = new InMemoryCache();

        $provider = new ProductRatingProvider(
            $api,
            $cache,
            new Json(),
            $this->configReturning('1')
        );

        $first = $provider->getAggregate('SKU-1', 1);
        $second = $provider->getAggregate('SKU-1', 1);

        $this->assertSame($first, $second);
        $this->assertSame(9.2, $first['ratingValue']);
    }

    private function configReturning(string $enabled): ScopeConfigInterface
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($enabled);

        return $config;
    }
}

class InMemoryCache implements CacheInterface
{
    /** @var array<string, string> */
    private $data = [];

    public function load($identifier)
    {
        return $this->data[$identifier] ?? false;
    }

    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->data[$identifier] = (string) $data;

        return true;
    }

    public function remove($identifier)
    {
        unset($this->data[$identifier]);

        return true;
    }

    public function clean($mode = null, $tags = [])
    {
        return true;
    }
}