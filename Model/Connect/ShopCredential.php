<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * Per-shop credential returned once by POST /shops/register.
 * Sent on /token, refresh, /revoke, and /shops/revoke.
 */
final class ShopCredential
{
    /**
     * @var string
     */
    public $shopId;

    /**
     * @var string
     */
    public $shopSecret;

    public function __construct(string $shopId, string $shopSecret)
    {
        $this->shopId = $shopId;
        $this->shopSecret = $shopSecret;
    }

    /**
     * POST /shops/register 200 body. The secret is returned once.
     *
     * @param array<string, mixed> $data
     */
    public static function fromRegistration(array $data, ShopAddress $shop): ?self
    {
        $clientId = self::scalar($data[ConnectFields::CLIENT_ID] ?? null);
        $origin = self::scalar($data[ConnectFields::SHOP_ORIGIN] ?? null);
        $redirect = self::scalar($data[ConnectFields::REDIRECT_URI] ?? null);
        $status = self::scalar($data[ConnectFields::STATUS] ?? null);
        if ($clientId !== ConnectProtocol::CLIENT_ID || $origin !== $shop->origin || $redirect !== $shop->redirectUri) {
            return null;
        }
        if ($status !== ConnectFields::STATUS_ACTIVE && $status !== ConnectFields::STATUS_PENDING) {
            return null;
        }

        return self::fromApi($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): ?self
    {
        $shopId = self::scalar($data[ConnectFields::SHOP_ID] ?? null);
        $shopSecret = self::scalar($data[ConnectFields::SHOP_SECRET] ?? null);
        if ($shopId === null || $shopSecret === null) {
            return null;
        }
        if (!self::safe($shopId, 200) || !self::safe($shopSecret, 500)) {
            return null;
        }

        return new self($shopId, $shopSecret);
    }

    /**
     * @return array<string, string>
     */
    public function asFormFields(): array
    {
        return [
            ConnectFields::SHOP_ID => $this->shopId,
            ConnectFields::SHOP_SECRET => $this->shopSecret,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function scalar($value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function safe(string $value, int $max): bool
    {
        if (strlen($value) > $max) {
            return false;
        }

        return preg_match('/^[^\s\x00-\x1F]+$/', $value) === 1;
    }
}
