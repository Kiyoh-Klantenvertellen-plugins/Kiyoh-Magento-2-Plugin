<?php

namespace Kiyoh\Reviews\Test\Unit\Publication;

use Kiyoh\Reviews\Model\Publication\PublicationServerHost;
use PHPUnit\Framework\TestCase;

class PublicationServerHostTest extends TestCase
{
    public function testKnownServersMapToPublicationHosts(): void
    {
        $this->assertSame('https://www.kiyoh.com', PublicationServerHost::baseUrlForConfigServer('kiyoh.com'));
        $this->assertSame(
            'https://www.klantenvertellen.nl',
            PublicationServerHost::baseUrlForConfigServer('klantenvertellen.nl')
        );
        $this->assertSame('https://www.kiyoh.com', PublicationServerHost::baseUrlForConfigServer('newkiyoh.com'));
    }

    public function testUnknownOrBlankReturnsNull(): void
    {
        $this->assertNull(PublicationServerHost::baseUrlForConfigServer(null));
        $this->assertNull(PublicationServerHost::baseUrlForConfigServer(''));
        $this->assertNull(PublicationServerHost::baseUrlForConfigServer('   '));
        $this->assertNull(PublicationServerHost::baseUrlForConfigServer('invalid.example'));
    }
}
