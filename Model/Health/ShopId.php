<?php

namespace Kiyoh\Reviews\Model\Health;

/**
 * Builds the health shopId from a store view base URL.
 *
 * The id is the store base host, plus a non-root base path, plus the store
 * code when the view is not the default view and the code is not already the
 * last path segment. One report is stored per store view.
 */
final class ShopId
{
    public static function fromStoreBaseUrl(string $baseUrl, string $storeCode): ?string
    {
        $parts = parse_url(trim($baseUrl));
        if (!is_array($parts) || empty($parts['host']) || !is_string($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '' || strpos($host, ' ') !== false) {
            return null;
        }

        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $host .= ':' . (int) $parts['port'];
        }

        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($path === 'index.php') {
            $path = '';
        } elseif ($path !== '' && substr($path, -10) === '/index.php') {
            $path = trim(substr($path, 0, -10), '/');
        }

        $shopId = $host;
        if ($path !== '') {
            $shopId .= '/' . $path;
        }

        $storeCode = trim($storeCode);
        if (
            $storeCode !== ''
            && strcasecmp($storeCode, 'default') !== 0
            && strcasecmp($storeCode, 'admin') !== 0
            && !self::endsWithSegment($shopId, $storeCode)
        ) {
            $shopId .= '/' . $storeCode;
        }

        return self::sanitize($shopId);
    }

    private static function endsWithSegment(string $shopId, string $segment): bool
    {
        return substr(strtolower($shopId), -strlen($segment) - 1) === '/' . strtolower($segment);
    }

    private static function sanitize(string $shopId): ?string
    {
        $shopId = preg_replace('/[^A-Za-z0-9._:@\/-]+/', '', $shopId) ?? '';
        $shopId = trim($shopId, '/');
        $shopId = ltrim($shopId, '._:@/-');

        if ($shopId === '') {
            return null;
        }

        if (strlen($shopId) > 200) {
            $shopId = rtrim(substr($shopId, 0, 200), '/');
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,199}$/', $shopId)) {
            return null;
        }

        return $shopId;
    }
}
