<?php

namespace Kiyoh\Reviews\Test\Unit\Connect;

use Kiyoh\Reviews\Model\Connect\CallbackGuard;
use Kiyoh\Reviews\Model\Connect\ScopeBaseUrl;
use Kiyoh\Reviews\Model\Connect\ShopAddress;
use PHPUnit\Framework\TestCase;

class ScopeReturnTest extends TestCase
{
    public function testStoreViewUsesItsOwnHostAndNotTheFirstStore(): void
    {
        $rows = [
            ['scope' => 'default', 'scope_id' => 0, 'value' => 'https://first.example/'],
            ['scope' => 'websites', 'scope_id' => 1, 'value' => 'https://website.example/'],
            ['scope' => 'stores', 'scope_id' => 1, 'value' => 'https://first.example/'],
            ['scope' => 'stores', 'scope_id' => 2, 'value' => 'https://second.example/nl/'],
        ];

        $second = ScopeBaseUrl::resolve($rows, 'stores', 2, 1);
        $this->assertSame('https://second.example/nl/', $second);
        $shop = ShopAddress::fromBaseUrl((string) $second);
        $this->assertNotNull($shop);
        $this->assertSame('https://second.example/kiyoh/oauth/callback', $shop->redirectUri);

        $first = ShopAddress::fromBaseUrl((string) ScopeBaseUrl::resolve($rows, 'stores', 1, 1));
        $this->assertNotNull($first);
        $this->assertNotSame($first->redirectUri, $shop->redirectUri);
    }

    public function testStoreViewFallsBackToItsWebsiteThenDefault(): void
    {
        $rows = [
            ['scope' => 'default', 'scope_id' => 0, 'value' => 'https://default.example/'],
            ['scope' => 'websites', 'scope_id' => 3, 'value' => 'https://site.example/'],
            ['scope' => 'stores', 'scope_id' => 9, 'value' => '{{secure_base_url}}'],
        ];

        $this->assertSame('https://site.example/', ScopeBaseUrl::resolve($rows, 'stores', 9, 3));
        $this->assertSame(
            'https://default.example/',
            ScopeBaseUrl::resolve(
                [['scope' => 'default', 'scope_id' => 0, 'value' => 'https://default.example/']],
                'stores',
                4,
                1
            )
        );
    }

    public function testOtherIssuerAndOtherStoreAreNotABrowserSuccess(): void
    {
        $this->assertTrue(CallbackGuard::isBrowserReturn('document', 'navigate', ''));
        $this->assertFalse(CallbackGuard::isBrowserReturn('', '', ''));
        $this->assertFalse(CallbackGuard::isBrowserReturn('document', 'navigate', 'prefetch'));
        $this->assertFalse(CallbackGuard::isBrowserReturn('empty', 'cors', ''));

        $redirect = 'https://second.example/kiyoh/oauth/callback';
        $this->assertTrue(CallbackGuard::hostMatches($redirect, 'second.example'));
        $this->assertTrue(CallbackGuard::hostMatches($redirect, 'SECOND.example:443'));
        $this->assertFalse(CallbackGuard::hostMatches($redirect, 'first.example'));
        $this->assertTrue(CallbackGuard::hostMatches($redirect, ''));
    }
}
