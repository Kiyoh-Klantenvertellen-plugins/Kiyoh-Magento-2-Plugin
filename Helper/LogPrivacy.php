<?php

namespace Kiyoh\Reviews\Helper;

/**
 * Redact customer identifiers before they reach application logs.
 */
final class LogPrivacy
{
    public static function maskEmail(?string $email): string
    {
        if ($email === null || $email === '') {
            return '[redacted]';
        }

        $at = strpos($email, '@');
        if ($at === false) {
            return '[redacted]';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        if ($local === '') {
            return '[redacted]@' . $domain;
        }

        $visible = substr($local, 0, 1);

        return $visible . '***@' . $domain;
    }
}