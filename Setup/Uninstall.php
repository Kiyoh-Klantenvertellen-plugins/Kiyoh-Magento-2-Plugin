<?php

namespace Kiyoh\Reviews\Setup;

use Kiyoh\Reviews\Model\Connect\UninstallRevoker;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Runs on bin/magento module:uninstall -r Kiyoh_Reviews.
 * Drops module tables and kiyoh_reviews/* configuration. Remote Connect revoke is best-effort.
 */
class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        try {
            $this->revokeRemote();
        } catch (\Throwable $e) {
            // Local data is still removed below. The remote session expires on its own.
        }

        $setup->startSetup();
        $connection = $setup->getConnection();
        foreach (['kiyoh_reviews_shop_health', 'kiyoh_reviews_connect_login'] as $table) {
            $name = $setup->getTable($table);
            if ($connection->isTableExists($name)) {
                $connection->dropTable($name);
            }
        }
        $connection->delete(
            $setup->getTable('core_config_data'),
            ['path LIKE ?' => 'kiyoh_reviews/%']
        );
        $setup->endSetup();
    }

    private function revokeRemote(): void
    {
        if (!defined('BP') || !class_exists(\Magento\Framework\App\Bootstrap::class)) {
            return;
        }

        $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
        $revoker = $bootstrap->getObjectManager()->get(UninstallRevoker::class);
        if ($revoker instanceof UninstallRevoker) {
            $revoker->revokeAll();
        }
    }
}
