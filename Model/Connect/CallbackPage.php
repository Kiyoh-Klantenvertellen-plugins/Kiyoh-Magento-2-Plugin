<?php

namespace Kiyoh\Reviews\Model\Connect;

final class CallbackPage
{
    public static function render(bool $ok, string $message): string
    {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $ok ? 'Connected' : 'Not connected';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="referrer" content="no-referrer">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . $title . '</title>'
            . '<style>body{font-family:sans-serif;margin:2rem;color:#1f2937}p{max-width:36rem}</style>'
            . '</head><body><h1>' . $title . '</h1><p>' . $safe . '</p>'
            . '<p>You can close this window and return to the Magento admin.</p></body></html>';
    }
}
