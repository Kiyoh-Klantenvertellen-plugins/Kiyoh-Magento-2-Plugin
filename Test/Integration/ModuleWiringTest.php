<?php

namespace Kiyoh\Reviews\Test\Integration;

use Kiyoh\Reviews\Model\ModuleMeta;
use PHPUnit\Framework\TestCase;

class ModuleWiringTest extends TestCase
{
    public function testModuleVersionMatchesTheReportedPluginVersion(): void
    {
        $xml = simplexml_load_file(KIYOH_MODULE_ROOT . '/etc/module.xml');
        $this->assertNotFalse($xml);
        $this->assertSame(ModuleMeta::VERSION, (string) $xml->module['setup_version']);
        $this->assertSame('1.4.0', ModuleMeta::VERSION);
    }

    public function testMobileUrlConfigDefaultsToKiyohMobile(): void
    {
        $config = file_get_contents(KIYOH_MODULE_ROOT . '/etc/config.xml');
        $system = file_get_contents(KIYOH_MODULE_ROOT . '/etc/adminhtml/system.xml');

        $this->assertIsString($config);
        $this->assertIsString($system);
        $this->assertStringContainsString('<mobile_urls>https://kiyoh-mobile.com</mobile_urls>', $config);
        $this->assertStringContainsString('<label>Kiyoh Mobile URL(s)</label>', $system);
        $this->assertStringContainsString('https://kiyohmobileui-staging.up.railway.app', $system);
    }

    public function testDailyHealthCronIsRegistered(): void
    {
        $xml = simplexml_load_file(KIYOH_MODULE_ROOT . '/etc/crontab.xml');
        $this->assertNotFalse($xml);

        $found = false;
        foreach ($xml->group->job as $job) {
            if ((string) $job['name'] !== 'kiyoh_reviews_mobile_health') {
                continue;
            }
            $found = true;
            $this->assertSame('Kiyoh\\Reviews\\Cron\\ReportMobileHealth', (string) $job['instance']);
            $this->assertSame('execute', (string) $job['method']);
            $this->assertSame('30 4 * * *', trim((string) $job->schedule));
        }

        $this->assertTrue($found, 'Daily mobile health cron is registered');
    }

    public function testHealthPreferencesSchemaAndConfigSaveObserverExist(): void
    {
        $di = file_get_contents(KIYOH_MODULE_ROOT . '/etc/di.xml');
        $schema = file_get_contents(KIYOH_MODULE_ROOT . '/etc/db_schema.xml');
        $events = file_get_contents(KIYOH_MODULE_ROOT . '/etc/adminhtml/events.xml');
        $api = file_get_contents(KIYOH_MODULE_ROOT . '/Service/ApiService.php');
        $order = file_get_contents(KIYOH_MODULE_ROOT . '/Observer/SalesOrderSaveAfter.php');

        $this->assertIsString($di);
        $this->assertStringContainsString('ShopHealthRepository', $di);
        $this->assertStringContainsString('CurlHealthTransport', $di);
        $this->assertStringContainsString('ConfigHealthContextProvider', $di);

        $this->assertIsString($schema);
        $this->assertStringContainsString('kiyoh_reviews_shop_health', $schema);

        $this->assertIsString($events);
        $this->assertStringContainsString('admin_system_config_changed_section_kiyoh_reviews', $events);
        $this->assertStringContainsString('ReportHealthOnConfigSave', $events);

        $this->assertIsString($api);
        $this->assertStringContainsString('noteInvite', $api);
        $this->assertStringContainsString('noteProductSync', $api);

        $this->assertIsString($order);
        $this->assertStringContainsString('deferPublishing()', $order);
        $this->assertStringContainsString('flushDeferred()', $order);
    }
}
