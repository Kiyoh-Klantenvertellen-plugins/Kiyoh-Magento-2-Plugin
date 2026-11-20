<?php

namespace Kiyoh\Reviews\Test\Unit\Health;

use Kiyoh\Reviews\Model\Health\MobileUrls;
use PHPUnit\Framework\TestCase;

class MobileUrlsTest extends TestCase
{
    public function testBlankValueUsesProductionDefault(): void
    {
        $expected = ['https://kiyoh-mobile.com'];
        $this->assertSame($expected, MobileUrls::parse(MobileUrls::DEFAULT_URLS));
        $this->assertSame($expected, MobileUrls::parse(null));
        $this->assertSame($expected, MobileUrls::parse(''));
        $this->assertSame($expected, MobileUrls::parse('   '));
        $this->assertSame($expected, MobileUrls::parse(','));
    }

    public function testCommaSeparatedUrlsAreTrimmedDedupedAndStripped(): void
    {
        $urls = MobileUrls::parse(
            ' https://kiyoh-mobile.com/, https://custom.example, https://kiyoh-mobile.com '
        );

        $this->assertSame(
            [
                'https://kiyoh-mobile.com',
                'https://custom.example',
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
        $this->assertSame(
            ['https://kiyoh-mobile.com'],
            MobileUrls::parse('ftp://files.example, ://bad')
        );
        $this->assertSame(
            ['https://shop.example'],
            MobileUrls::parse('ftp://files.example, https://shop.example')
        );
    }

    public function testStripStagingFromStoredRemovesStagingHostOnly(): void
    {
        $both = 'https://kiyoh-mobile.com,https://kiyohmobileui-staging.up.railway.app';
        $this->assertSame('https://kiyoh-mobile.com', MobileUrls::stripStagingFromStored($both));

        $custom = 'https://shop.example,https://kiyohmobileui-staging.up.railway.app';
        $this->assertSame('https://shop.example', MobileUrls::stripStagingFromStored($custom));

        $this->assertNull(MobileUrls::stripStagingFromStored('https://kiyoh-mobile.com'));
        $this->assertNull(MobileUrls::stripStagingFromStored(null));
    }

    public function testStripStagingFromStoredFallsBackToProductionWhenOnlyStaging(): void
    {
        $this->assertSame(
            MobileUrls::DEFAULT_URLS,
            MobileUrls::stripStagingFromStored('https://kiyohmobileui-staging.up.railway.app')
        );
    }
}