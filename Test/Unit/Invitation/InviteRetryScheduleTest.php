<?php

namespace Kiyoh\Reviews\Test\Unit\Invitation;

use Kiyoh\Reviews\Model\Invitation\InviteRetrySchedule;
use PHPUnit\Framework\TestCase;

class InviteRetryScheduleTest extends TestCase
{
    public function testBackoffStopsAfterMaxAttempts(): void
    {
        $this->assertNotNull(InviteRetrySchedule::nextRetryTimestamp(1));
        $this->assertNotNull(InviteRetrySchedule::nextRetryTimestamp(2));
        $this->assertNull(InviteRetrySchedule::nextRetryTimestamp(3));
    }
}