<?php

namespace Kiyoh\Reviews\Model\Health;

/**
 * Parses the comma-separated "Kiyoh Mobile URL(s)" config value.
 */
final class MobileUrls
{
    /**
     * Comma-separated default. Keep this identical to etc/config.xml mobile_urls.
     */
    public const DEFAULT_URLS = 'https://kiyoh-mobile.com,https://kiyohmobileui-staging.up.railway.app';

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
