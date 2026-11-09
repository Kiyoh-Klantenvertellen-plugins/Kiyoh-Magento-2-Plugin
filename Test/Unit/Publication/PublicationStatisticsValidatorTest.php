<?php

namespace Kiyoh\Reviews\Test\Unit\Publication;

use Kiyoh\Reviews\Model\Connect\ConnectProtocol;
use Kiyoh\Reviews\Model\Publication\PublicationStatisticsValidator;
use PHPUnit\Framework\TestCase;

class PublicationStatisticsValidatorTest extends TestCase
{
    public function testReturnsNullWhenTenantMatchesKiyoh(): void
    {
        $msg = PublicationStatisticsValidator::tenantMismatchMessage('kiyoh.com', [
            'tenantId' => ConnectProtocol::TENANT_KIYOH,
            'locationName' => 'Test',
        ]);
        $this->assertNull($msg);
    }

    public function testReturnsNullWhenTenantMatchesKlantenvertellen(): void
    {
        $msg = PublicationStatisticsValidator::tenantMismatchMessage('klantenvertellen.nl', [
            'tenantId' => ConnectProtocol::TENANT_KLANTENVERTELLEN,
            'locationName' => 'Test',
        ]);
        $this->assertNull($msg);
    }

    public function testFailsWhenKiyohCredentialsUsedWithKlantenvertellenServer(): void
    {
        $msg = PublicationStatisticsValidator::tenantMismatchMessage('klantenvertellen.nl', [
            'tenantId' => ConnectProtocol::TENANT_KIYOH,
            'locationName' => 'Test',
        ]);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Kiyoh (kiyoh.com)', (string) $msg);
        $this->assertStringContainsString('klantenvertellen.nl', (string) $msg);
    }

    public function testFailsWhenKlantenvertellenCredentialsUsedWithKiyohServer(): void
    {
        $msg = PublicationStatisticsValidator::tenantMismatchMessage('kiyoh.com', [
            'tenantId' => ConnectProtocol::TENANT_KLANTENVERTELLEN,
            'locationName' => 'Test',
        ]);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Klantenvertellen (klantenvertellen.nl)', (string) $msg);
        $this->assertStringContainsString('kiyoh.com', (string) $msg);
    }

    public function testIgnoresMissingTenantId(): void
    {
        $this->assertNull(PublicationStatisticsValidator::tenantMismatchMessage('kiyoh.com', [
            'locationName' => 'Test',
        ]));
    }
}
