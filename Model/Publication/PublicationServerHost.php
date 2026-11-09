<?php

namespace Kiyoh\Reviews\Model\Publication;

/**
 * Maps the admin "Server" config to the publication API base URL.
 * Unknown values must not fall back to another platform (see KP-94).
 */
final class PublicationServerHost
{
    /**
     * @return string|null HTTPS origin including host, no trailing slash, or null when unknown.
     */
    public static function baseUrlForConfigServer(?string $server): ?string
    {
        if ($server === null) {
            return null;
        }
        $server = trim($server);
        if ($server === '') {
            return null;
        }

        $map = [
            'klantenvertellen.nl' => 'https://www.klantenvertellen.nl',
            'newkiyoh.com' => 'https://www.kiyoh.com',
            'kiyoh.com' => 'https://www.kiyoh.com',
        ];

        return $map[$server] ?? null;
    }
}
