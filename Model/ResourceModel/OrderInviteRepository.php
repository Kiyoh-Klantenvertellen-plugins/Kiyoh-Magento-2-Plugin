<?php

namespace Kiyoh\Reviews\Model\ResourceModel;

use Kiyoh\Reviews\Model\OrderInviteStatus;
use Magento\Framework\App\ResourceConnection;

class OrderInviteRepository
{
    private const TABLE = 'kiyoh_reviews_order_invite';

    /**
     * @var ResourceConnection
     */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

  /**
   * @return array<string, mixed>|null
   */
    public function getByOrderId(int $orderId): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('order_id = ?', $orderId);

        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    public function shouldSkipSending(int $orderId): bool
    {
        $row = $this->getByOrderId($orderId);
        if ($row === null) {
            return false;
        }

        if (($row['invite_status'] ?? '') === OrderInviteStatus::SENT) {
            return true;
        }

        return (int) ($row['attempt_count'] ?? 0) >= OrderInviteStatus::MAX_ATTEMPTS;
    }

    public function markSent(int $orderId, int $storeId): void
    {
        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'order_id' => $orderId,
                'store_id' => $storeId,
                'invite_status' => OrderInviteStatus::SENT,
                'attempt_count' => 0,
                'last_error' => null,
                'next_retry_at' => null,
            ],
            [
                'invite_status',
                'attempt_count',
                'last_error',
                'next_retry_at',
                'updated_at',
            ]
        );
    }

    public function markFailed(
        int $orderId,
        int $storeId,
        string $errorMessage,
        int $attemptCount,
        ?int $nextRetryAt
    ): void {
        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'order_id' => $orderId,
                'store_id' => $storeId,
                'invite_status' => OrderInviteStatus::FAILED,
                'attempt_count' => $attemptCount,
                'last_error' => mb_substr($errorMessage, 0, 2000),
                'next_retry_at' => $nextRetryAt,
            ],
            [
                'invite_status',
                'attempt_count',
                'last_error',
                'next_retry_at',
                'updated_at',
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDueForRetry(int $limit = 50): array
    {
        $connection = $this->resource->getConnection();
        $now = time();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('invite_status = ?', OrderInviteStatus::FAILED)
            ->where('attempt_count < ?', OrderInviteStatus::MAX_ATTEMPTS)
            ->where('next_retry_at IS NULL OR next_retry_at <= ?', $now)
            ->order('next_retry_at ASC')
            ->limit($limit);

        $rows = $connection->fetchAll($select);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<array{order_id: int, store_id: int}> $rows
     */
    public function insertSentBackfill(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        foreach ($rows as $row) {
            $connection->insertOnDuplicate(
                $table,
                [
                    'order_id' => (int) $row['order_id'],
                    'store_id' => (int) $row['store_id'],
                    'invite_status' => OrderInviteStatus::SENT,
                    'attempt_count' => 0,
                    'last_error' => null,
                    'next_retry_at' => null,
                ],
                ['invite_status', 'attempt_count', 'last_error', 'next_retry_at']
            );
        }
    }
}