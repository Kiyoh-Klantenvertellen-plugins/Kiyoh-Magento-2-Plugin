<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * Kiyoh-Connect 1.2 public client kiyoh-magento2.
 *
 * POST /shops/register takes no shared key and returns a one-time per-shop
 * credential. That credential is stored encrypted and sent on later calls.
 * This module does not embed a shared registration key.
 */
final class ConnectProtocol
{
    public const CLIENT_ID = 'kiyoh-magento2';
    public const SCOPE = 'integration:connect';
    public const CODE_CHALLENGE_METHOD = 'S256';
    public const CALLBACK_PATH = '/kiyoh/oauth/callback';
    public const LOGIN_TTL_SECONDS = 900;
    public const TENANT_KIYOH = 98;
    public const TENANT_KLANTENVERTELLEN = 99;

    public const ISSUER_KIYOH = 'https://connect.kiyoh.com';
    public const ISSUER_KLANTENVERTELLEN = 'https://connect.klantenvertellen.nl';

    public static function issuerForServer(string $server): ?string
    {
        if ($server === 'kiyoh.com') {
            return self::ISSUER_KIYOH;
        }
        if ($server === 'klantenvertellen.nl') {
            return self::ISSUER_KLANTENVERTELLEN;
        }

        return null;
    }

    public static function expectedTenant(string $server): ?int
    {
        if ($server === 'kiyoh.com') {
            return self::TENANT_KIYOH;
        }
        if ($server === 'klantenvertellen.nl') {
            return self::TENANT_KLANTENVERTELLEN;
        }

        return null;
    }
}
