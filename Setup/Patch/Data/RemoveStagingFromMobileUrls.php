<?php

namespace Kiyoh\Reviews\Setup\Patch\Data;

use Kiyoh\Reviews\Model\Health\MobileUrls;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drop the tester staging host from saved mobile_urls values. Other lists are left as-is.
 */
class RemoveStagingFromMobileUrls implements DataPatchInterface
{
    private const PATH = 'kiyoh_reviews/api_settings/mobile_urls';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');
        $connection->startSetup();

        $select = $connection->select()
            ->from($table, ['config_id', 'value'])
            ->where('path = ?', self::PATH)
            ->where('value LIKE ?', '%' . MobileUrls::STAGING_HOST . '%');

        foreach ($connection->fetchAll($select) as $row) {
            $cleaned = MobileUrls::stripStagingFromStored((string) $row['value']);
            if ($cleaned === null) {
                continue;
            }
            $connection->update(
                $table,
                ['value' => $cleaned],
                ['config_id = ?' => (int) $row['config_id']]
            );
        }

        $connection->endSetup();

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function getDependencies(): array
    {
        return [
            SendHealthToProductionAndStaging::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function getAliases(): array
    {
        return [];
    }
}