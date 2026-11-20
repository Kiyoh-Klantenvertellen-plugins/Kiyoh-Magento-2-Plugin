<?php

namespace Kiyoh\Reviews\Model\Health;

/**
 * Parses the comma-separated kiyoh_reviews/api_settings/mobile_urls config value.
 * Not exposed in admin; merchants use the production default unless testers override via CLI/env.
 */
final class MobileUrls
{
    /**
     * Comma-separated default. Keep this identical to etc/config.xml mobile_urls.
     */
    public const DEFAULT_URLS = 'https://kiyoh-mobile.com';

    /**
     * Staging host removed from defaults and from stored config on upgrade (KP-173).
     */
    public const STAGING_HOST = 'kiyohmobileui-staging.up.railway.app';

    /**
     * @return list<string>
     */
    public static function parse(?string $value): array
    {
        $urls = self::collect($value);
        if ($urls === []) {
            return self::collect(self::DEFAULT_URLS);
        }

        return $urls;
    }

    /**
     * When a saved value still references staging, return the cleaned comma-separated list;
     * otherwise null (leave the row unchanged).
     */
    public static function stripStagingFromStored(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (stripos($value, self::STAGING_HOST) === false) {
            return null;
        }

        $urls = array_values(array_filter(
            self::collect($value),
            static fn (string $url): bool => stripos($url, self::STAGING_HOST) === false
        ));

        if ($urls === []) {
            return self::DEFAULT_URLS;
        }

        return implode(',', $urls);
    }

    /**
     * @return list<string>
     */
    private static function collect(?string $value): array
    {
        $urls = [];
        foreach (explode(',', trim((string) $value)) as $segment) {
            $normalized = self::normalize(trim($segment));
            if ($normalized === null || isset($urls[$normalized])) {
                continue;
            }
            $urls[$normalized] = true;
        }

        return array_keys($urls);
    }

    private static function normalize(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $url, $schemeMatch)) {
            $scheme = strtolower($schemeMatch[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
        } else {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !is_string($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '' || strpos($host, ' ') !== false) {
            return null;
        }

        $normalized = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }

        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($path !== '') {
            $normalized .= '/' . $path;
        }

        return $normalized;
    }
}