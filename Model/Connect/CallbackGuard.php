<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * The OAuth browser return is the only request allowed to save credentials.
 */
final class CallbackGuard
{
    public static function isBrowserReturn(string $dest, string $mode, string $purpose): bool
    {
        $dest = strtolower(trim($dest));
        $mode = strtolower(trim($mode));
        $purpose = strtolower(trim($purpose));
        if ($purpose !== '' && strpos($purpose, 'prefetch') !== false) {
            return false;
        }

        return $dest === 'document' || $mode === 'navigate';
    }

    public static function hostMatches(string $redirectUri, string $requestHost): bool
    {
        $requestHost = strtolower(trim($requestHost));
        if ($requestHost === '') {
            return true;
        }
        $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?? $requestHost;
        $expected = parse_url($redirectUri, PHP_URL_HOST);
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $expected = strtolower($expected);
        if (strlen($expected) !== strlen($requestHost)) {
            return false;
        }

        return hash_equals($expected, $requestHost);
    }
}
