<?php

namespace Kiyoh\Reviews\Setup\Patch\Data;

use Kiyoh\Reviews\Model\Health\MobileUrls;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * A store view that saved the 1.5.0 default still has only the production URL
 * in core_config_data. Move that exact value to production and staging.
 * Any other list is a merchant choice and is left as saved.
 */
class SendHealthToProductionAndStaging implements DataPatchInterface
{
    private const PATH = 'kiyoh_reviews/api_settings/mobile_urls';
    private const PREVIOUS_DEFAULT = 'https://kiyoh-mobile.com';

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
        $connection->startSetup();
        $connection->update(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['value' => MobileUrls::DEFAULT_URLS],
            [
                'path = ?' => self::PATH,
                'value = ?' => self::PREVIOUS_DEFAULT,
            ]
        );
        $connection->endSetup();

        return $this;
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
