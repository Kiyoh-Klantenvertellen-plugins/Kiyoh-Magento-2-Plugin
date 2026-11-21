<?php

namespace Kiyoh\Reviews\Model;

final class OrderInviteStatus
{
    public const SENT = 'sent';
    public const FAILED = 'failed';

    public const MAX_ATTEMPTS = 3;
}