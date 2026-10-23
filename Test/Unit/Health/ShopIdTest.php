<?php

namespace Kiyoh\Reviews\Test\Unit\Health;

use Kiyoh\Reviews\Model\Health\ShopId;
use PHPUnit\Framework\TestCase;

class ShopIdTest extends TestCase
{
    public function testDefaultStoreUsesTheBaseHost(): void
    {
        $this->assertSame(
            'shop.example.com',
            ShopId::fromStoreBaseUrl('https://Shop.Example.com/', 'default')
        );
    }

    public function testNonDefaultStoreCodeIsAppended(): void
    {
        $this->assertSame(
            'shop.example.com/nl',
            ShopId::fromStoreBaseUrl('https://shop.example.com/', 'nl')
        );
    }

    public function testStoreCodeAlreadyInThePathIsNotRepeated(): void
    {
        $this->assertSame(
            'shop.example.com/NL',
            ShopId::fromStoreBaseUrl('https://shop.example.com/NL/', 'nl')
        );
    }

    public function testSubdirectoryInstallKeepsThePathAndStoreCode(): void
    {
        $this->assertSame(
            'shop.example.com/magento/fr',
            ShopId::fromStoreBaseUrl('https://shop.example.com/magento/', 'fr')
        );
    }

    public function testIndexPhpAndStandardPortsAreOmitted(): void
    {
        $this->assertSame(
            'shop.example.com',
            ShopId::fromStoreBaseUrl('https://shop.example.com/index.php/', 'default')
        );
        $this->assertSame(
            'shop.example.com/b2b',
            ShopId::fromStoreBaseUrl('https://shop.example.com:443/', 'b2b')
        );
        $this->assertSame(
            'shop.example.com:8443',
            ShopId::fromStoreBaseUrl('https://shop.example.com:8443/', 'admin')
        );
    }

    public function testMissingHostReturnsNull(): void
    {
        $this->assertNull(ShopId::fromStoreBaseUrl('', 'default'));
        $this->assertNull(ShopId::fromStoreBaseUrl('not a url', 'nl'));
        $this->assertNull(ShopId::fromStoreBaseUrl('https://', 'nl'));
    }

    public function testShopIdStaysWithinTheApiLimit(): void
    {
        $host = str_repeat('a', 250);
        $shopId = ShopId::fromStoreBaseUrl('https://' . $host . '/', 'default');

        $this->assertNotNull($shopId);
        $this->assertSame(200, strlen((string) $shopId));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,199}$/', (string) $shopId);
    }
}
