<?php

namespace Kiyoh\Reviews\Model\Health;

class ShopHealthRecord
{
    /**
     * @var int
     */
    public $storeId;

    /**
     * @var string
     */
    public $connectionStatus = 'unknown';

    /**
     * @var string|null
     */
    public $connectionLastOkAt = null;

    /**
     * @var string|null
     */
    public $connectionLastError = null;

    /**
     * @var string|null
     */
    public $invitesLastOkAt = null;

    /**
     * @var list<string>
     */
    public $inviteFailureAts = [];

    /**
     * @var string|null
     */
    public $productSyncLastOkAt = null;

    /**
     * @var string|null
     */
    public $productSyncLastError = null;

    /**
     * @var list<string>
     */
    public $productCodes = [];

    /**
     * @var string|null
     */
    public $lastReportedDigest = null;

    /**
     * @var string|null
     */
    public $lastReportedAt = null;

    /**
     * Unix timestamp per mobile base URL. Posts to that URL wait until the timestamp.
     *
     * @var array<string, int>
     */
    public $rateLimitedUntil = [];

    public function __construct(int $storeId)
    {
        $this->storeId = $storeId;
    }

    public static function empty(int $storeId): self
    {
        return new self($storeId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(int $storeId, array $data): self
    {
        $record = new self($storeId);
        $status = $data['connectionStatus'] ?? 'unknown';
        $record->connectionStatus = is_string($status) ? $status : 'unknown';
        $record->connectionLastOkAt = self::stringOrNull($data['connectionLastOkAt'] ?? null);
        $record->connectionLastError = self::stringOrNull($data['connectionLastError'] ?? null);
        $record->invitesLastOkAt = self::stringOrNull($data['invitesLastOkAt'] ?? null);
        $record->inviteFailureAts = self::stringList($data['inviteFailureAts'] ?? []);
        $record->productSyncLastOkAt = self::stringOrNull($data['productSyncLastOkAt'] ?? null);
        $record->productSyncLastError = self::stringOrNull($data['productSyncLastError'] ?? null);
        $record->productCodes = self::stringList($data['productCodes'] ?? []);
        $record->lastReportedDigest = self::stringOrNull($data['lastReportedDigest'] ?? null);
        $record->lastReportedAt = self::stringOrNull($data['lastReportedAt'] ?? null);

        $rates = $data['rateLimitedUntil'] ?? [];
        if (is_array($rates)) {
            foreach ($rates as $url => $until) {
                if (is_string($url) && is_numeric($until)) {
                    $record->rateLimitedUntil[$url] = (int) $until;
                }
            }
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connectionStatus' => $this->connectionStatus,
            'connectionLastOkAt' => $this->connectionLastOkAt,
            'connectionLastError' => $this->connectionLastError,
            'invitesLastOkAt' => $this->invitesLastOkAt,
            'inviteFailureAts' => array_values($this->inviteFailureAts),
            'productSyncLastOkAt' => $this->productSyncLastOkAt,
            'productSyncLastError' => $this->productSyncLastError,
            'productCodes' => array_values($this->productCodes),
            'lastReportedDigest' => $this->lastReportedDigest,
            'lastReportedAt' => $this->lastReportedAt,
            'rateLimitedUntil' => $this->rateLimitedUntil,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function stringOrNull($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
