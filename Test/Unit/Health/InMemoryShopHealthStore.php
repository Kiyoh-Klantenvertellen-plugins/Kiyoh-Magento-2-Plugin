<?php

namespace Kiyoh\Reviews\Test\Unit\Health;

use Kiyoh\Reviews\Model\Health\ShopHealthRecord;
use Kiyoh\Reviews\Model\Health\ShopHealthStoreInterface;

final class InMemoryShopHealthStore implements ShopHealthStoreInterface
{
    /**
     * @var array<int, string>
     */
    private $json = [];

    public function load(int $storeId): ShopHealthRecord
    {
        if (!isset($this->json[$storeId])) {
            return ShopHealthRecord::empty($storeId);
        }

        $data = json_decode($this->json[$storeId], true);
        if (!is_array($data)) {
            return ShopHealthRecord::empty($storeId);
        }

        return ShopHealthRecord::fromArray($storeId, $data);
    }

    public function save(ShopHealthRecord $record): void
    {
        $encoded = json_encode($record->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to store health state');
        }

        $this->json[$record->storeId] = $encoded;
    }
}
