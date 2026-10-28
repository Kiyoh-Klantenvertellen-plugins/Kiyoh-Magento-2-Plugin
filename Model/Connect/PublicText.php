<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * Text that is safe to show to a merchant. Drops anything that looks like a secret.
 */
final class PublicText
{
    public static function clean(?string $text, string $fallback): string
    {
        if (!is_string($text)) {
            return $fallback;
        }

        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = str_replace('%', '', $text);
        if ($text === '' || strlen($text) > 180) {
            return $fallback;
        }
        if (preg_match('/secret|bearer|api[_ ]?key|hmac|verifier/i', $text)) {
            return $fallback;
        }
        if (preg_match('/[A-Za-z0-9_\-]{24,}/', $text)) {
            return $fallback;
        }

        return $text;
    }
}
