<?php

namespace Kiyoh\Reviews\Model\ResourceModel;

use Kiyoh\Reviews\Model\Health\ShopHealthRecord;
use Kiyoh\Reviews\Model\Health\ShopHealthStoreInterface;
use Magento\Framework\App\ResourceConnection;

class ShopHealthRepository implements ShopHealthStoreInterface
{
    private const TABLE = 'kiyoh_reviews_shop_health';

    /**
     * @var ResourceConnection
     */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function load(int $storeId): ShopHealthRecord
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $json = $connection->fetchOne(
            $connection->select()->from($table, ['state_json'])->where('store_id = ?', $storeId)
        );

        if (!is_string($json) || $json === '') {
            return ShopHealthRecord::empty($storeId);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ShopHealthRecord::empty($storeId);
        }

        return ShopHealthRecord::fromArray($storeId, $data);
    }

    public function save(ShopHealthRecord $record): void
    {
        $json = json_encode($record->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode shop health state');
        }

        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'store_id' => $record->storeId,
                'state_json' => $json,
            ],
            ['state_json']
        );
    }
}
