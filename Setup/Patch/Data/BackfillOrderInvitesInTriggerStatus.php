<?php

namespace Kiyoh\Reviews\Setup\Patch\Data;

use Kiyoh\Reviews\Model\Invitation\OrderStatusTransition;
use Kiyoh\Reviews\Model\ResourceModel\OrderInviteRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Orders already sitting in a trigger status before this upgrade are treated as already invited,
 * so a later save (comment, invoice) cannot fire a duplicate invitation.
 */
class BackfillOrderInvitesInTriggerStatus implements DataPatchInterface
{
    private const CONFIG_PATH_ORDER_STATUS_TRIGGER = 'kiyoh_reviews/review_invitations/order_status_trigger';
    private const DEFAULT_TRIGGER = 'complete';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var OrderInviteRepository
     */
    private $inviteRepository;

  /**
   * @var ResourceConnection
   */
    private $resource;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        OrderInviteRepository $inviteRepository,
        ResourceConnection $resource
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->inviteRepository = $inviteRepository;
        $this->resource = $resource;
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $triggerStatuses = $this->collectTriggerStatuses();
        if ($triggerStatuses === []) {
            return $this;
        }

        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $orderTable = $this->moduleDataSetup->getTable('sales_order');
        $select = $connection->select()
            ->from($orderTable, ['entity_id', 'store_id'])
            ->where('status IN (?)', $triggerStatuses);

        $rows = $connection->fetchAll($select);
        $backfill = [];
        foreach ($rows as $row) {
            $backfill[] = [
                'order_id' => (int) $row['entity_id'],
                'store_id' => (int) $row['store_id'],
            ];
        }

        $this->inviteRepository->insertSentBackfill($backfill);

        $connection->endSetup();

        return $this;
    }

    /**
     * @return list<string>
     */
    private function collectTriggerStatuses(): array
    {
        $connection = $this->resource->getConnection();
        $configTable = $this->resource->getTableName('core_config_data');
        $values = $connection->fetchCol(
            $connection->select()
                ->from($configTable, ['value'])
                ->where('path = ?', self::CONFIG_PATH_ORDER_STATUS_TRIGGER)
        );

        $all = [self::DEFAULT_TRIGGER];
        foreach ($values as $csv) {
            if (!is_string($csv) || $csv === '') {
                continue;
            }
            $all = array_merge($all, OrderStatusTransition::parseTriggerStatuses($csv));
        }

        return array_values(array_unique($all));
    }

    /**
     * @return list<string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function getAliases(): array
    {
        return [];
    }
}