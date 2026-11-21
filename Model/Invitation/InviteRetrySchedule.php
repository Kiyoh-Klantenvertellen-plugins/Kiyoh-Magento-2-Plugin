<?php

namespace Kiyoh\Reviews\Model\Invitation;

use Kiyoh\Reviews\Model\OrderInviteStatus;

final class InviteRetrySchedule
{
    private const BACKOFF_SECONDS = [3600, 14400, 43200];

    public static function nextRetryTimestamp(int $attemptCount): ?int
    {
        if ($attemptCount >= OrderInviteStatus::MAX_ATTEMPTS) {
            return null;
        }

        $index = max(0, min($attemptCount - 1, count(self::BACKOFF_SECONDS) - 1));

        return time() + self::BACKOFF_SECONDS[$index];
    }
}