<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * GET /integration/credentials body: locationId, tenantId, locationName, inviteApiKey.
 */
final class CredentialSet
{
    /**
     * @var string
     */
    public $locationId;

    /**
     * @var int
     */
    public $tenantId;

    /**
     * @var string
     */
    public $locationName;

    /**
     * @var string
     */
    public $inviteApiKey;

    public function __construct(string $locationId, int $tenantId, string $locationName, string $inviteApiKey)
    {
        $this->locationId = $locationId;
        $this->tenantId = $tenantId;
        $this->locationName = $locationName;
        $this->inviteApiKey = $inviteApiKey;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): ?self
    {
        $locationId = self::scalar($data[ConnectFields::LOCATION_ID] ?? null);
        $inviteApiKey = self::scalar($data[ConnectFields::INVITE_API_KEY] ?? null);
        $tenantRaw = $data[ConnectFields::TENANT_ID] ?? null;
        if ($locationId === null || $inviteApiKey === null || !self::numeric($tenantRaw)) {
            return null;
        }
        if (!preg_match('/^\d{1,20}$/', $locationId)) {
            return null;
        }
        if (strlen($inviteApiKey) > 500) {
            return null;
        }

        $name = self::scalar($data[ConnectFields::LOCATION_NAME] ?? null) ?? '';
        $name = trim(strip_tags($name));
        if (strlen($name) > 200) {
            $name = substr($name, 0, 200);
        }
        $name = str_replace('%', '', $name);

        return new self($locationId, (int) $tenantRaw, $name, $inviteApiKey);
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

    /**
     * @param mixed $value
     */
    private static function numeric($value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }
        return is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1;
    }
}
