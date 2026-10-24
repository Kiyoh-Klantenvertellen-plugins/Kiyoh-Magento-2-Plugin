<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * Per-shop return address. The callback is always https://{shop-host}/kiyoh/oauth/callback.
 */
final class ShopAddress
{
    /**
     * @var string scheme://host[:port]
     */
    public $origin;

    /**
     * @var string Store base URL sent as shop_url.
     */
    public $shopUrl;

    /**
     * @var string Exact redirect_uri registered with Kiyoh-Connect.
     */
    public $redirectUri;

    public function __construct(string $origin, string $shopUrl, string $redirectUri)
    {
        $this->origin = $origin;
        $this->shopUrl = $shopUrl;
        $this->redirectUri = $redirectUri;
    }

    /**
     * @return self|null null when the base URL is not a usable HTTPS shop URL
     */
    public static function fromBaseUrl(string $baseUrl): ?self
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '' || strlen($baseUrl) > 500) {
            return null;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || strpos($host, ' ') !== false) {
            return null;
        }

        $origin = 'https://' . $host;
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return null;
            }
            if ($port !== 443) {
                $origin .= ':' . $port;
            }
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = preg_replace('#/index\.php/?$#', '/', $path) ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }

        $shopUrl = $origin . $path;
        $redirectUri = $origin . ConnectProtocol::CALLBACK_PATH;
        if (strlen($redirectUri) > 500 || strlen($shopUrl) > 500) {
            return null;
        }

        return new self($origin, $shopUrl, $redirectUri);
    }
}
