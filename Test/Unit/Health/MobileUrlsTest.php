<?php

namespace Kiyoh\Reviews\Test\Unit\Health;

use Kiyoh\Reviews\Model\Health\MobileUrls;
use PHPUnit\Framework\TestCase;

class MobileUrlsTest extends TestCase
{
    public function testBlankValueUsesTheDefaultUrl(): void
    {
        $this->assertSame(['https://kiyoh-mobile.com'], MobileUrls::parse(null));
        $this->assertSame(['https://kiyoh-mobile.com'], MobileUrls::parse(''));
        $this->assertSame(['https://kiyoh-mobile.com'], MobileUrls::parse('   '));
        $this->assertSame(['https://kiyoh-mobile.com'], MobileUrls::parse(','));
    }

    public function testCommaSeparatedUrlsAreTrimmedDedupedAndStripped(): void
    {
        $urls = MobileUrls::parse(
            ' https://kiyoh-mobile.com/, https://kiyohmobileui-staging.up.railway.app, https://kiyoh-mobile.com '
        );

        $this->assertSame(
            [
                'https://kiyoh-mobile.com',
                'https://kiyohmobileui-staging.up.railway.app',
            ],
            $urls
        );
    }

    public function testSchemeIsAddedAndUserInfoQueryAreDropped(): void
    {
        $this->assertSame(
            ['https://example.com/base'],
            MobileUrls::parse('example.com/base/?q=1')
        );
        $this->assertSame(
            ['https://kiyoh-mobile.com/path'],
            MobileUrls::parse('https://user:secret@kiyoh-mobile.com/path/')
        );
        $this->assertSame(
            ['http://localhost:8080'],
            MobileUrls::parse('http://localhost:8080/')
        );
    }

    public function testInvalidSegmentsFallBackWhenNothingRemains(): void
    {
        $this->assertSame(['https://kiyoh-mobile.com'], MobileUrls::parse('ftp://files.example, ://bad'));
        $this->assertSame(
            ['https://shop.example'],
            MobileUrls::parse('ftp://files.example, https://shop.example')
        );
    }
}
