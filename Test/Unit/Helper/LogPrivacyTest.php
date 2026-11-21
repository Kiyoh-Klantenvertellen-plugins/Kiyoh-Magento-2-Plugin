<?php

namespace Kiyoh\Reviews\Test\Unit\Helper;

use Kiyoh\Reviews\Helper\LogPrivacy;
use PHPUnit\Framework\TestCase;

class LogPrivacyTest extends TestCase
{
    public function testMasksCustomerEmail(): void
    {
        $this->assertSame('j***@example.com', LogPrivacy::maskEmail('john@example.com'));
    }

    public function testRedactsMissingEmail(): void
    {
        $this->assertSame('[redacted]', LogPrivacy::maskEmail(null));
        $this->assertSame('[redacted]', LogPrivacy::maskEmail(''));
    }
}