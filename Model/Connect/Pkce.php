<?php

namespace Kiyoh\Reviews\Model\Connect;

final class Pkce
{
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function state(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
