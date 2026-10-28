<?php

namespace Kiyoh\Reviews\Test\Integration;

use Kiyoh\Reviews\Model\Health\MobileUrls;
use Kiyoh\Reviews\Model\ModuleMeta;
use PHPUnit\Framework\TestCase;

class ModuleWiringTest extends TestCase
{
    public function testModuleVersionMatchesTheReportedPluginVersion(): void
    {
        $xml = simplexml_load_file(KIYOH_MODULE_ROOT . '/etc/module.xml');
        $this->assertNotFalse($xml);
        $this->assertSame(ModuleMeta::VERSION, (string) $xml->module['setup_version']);
        $composer = json_decode((string) file_get_contents(KIYOH_MODULE_ROOT . '/composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertSame(ModuleMeta::VERSION, $composer['version'] ?? null);
        $this->assertSame('1.5.2', ModuleMeta::VERSION);
    }

    public function testMobileUrlConfigDefaultsToProductionAndStaging(): void
    {
        $config = file_get_contents(KIYOH_MODULE_ROOT . '/etc/config.xml');
        $system = file_get_contents(KIYOH_MODULE_ROOT . '/etc/adminhtml/system.xml');

        $this->assertIsString($config);
        $this->assertIsString($system);
        $this->assertStringContainsString(
            '<mobile_urls>' . MobileUrls::DEFAULT_URLS . '</mobile_urls>',
            $config
        );
        $this->assertSame(
            [
                'https://kiyoh-mobile.com',
                'https://kiyohmobileui-staging.up.railway.app',
            ],
            MobileUrls::parse(MobileUrls::DEFAULT_URLS)
        );
        $this->assertStringContainsString('<label>Kiyoh Mobile URL(s)</label>', $system);
        $this->assertStringContainsString(
            'Default: https://kiyoh-mobile.com, https://kiyohmobileui-staging.up.railway.app.',
            $system
        );

        $patch = file_get_contents(
            KIYOH_MODULE_ROOT . '/Setup/Patch/Data/SendHealthToProductionAndStaging.php'
        );
        $this->assertIsString($patch);
        $this->assertStringContainsString('https://kiyoh-mobile.com', $patch);
        $this->assertStringContainsString('MobileUrls::DEFAULT_URLS', $patch);
        $this->assertStringContainsString('kiyoh_reviews/api_settings/mobile_urls', $patch);
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

    public function testTlsStaysOnAndTheTestCommandDoesNotUseObjectManager(): void
    {
        $api = file_get_contents(KIYOH_MODULE_ROOT . '/Service/ApiService.php');
        $transport = file_get_contents(KIYOH_MODULE_ROOT . '/Model/Connect/CurlConnectTransport.php');
        $command = file_get_contents(KIYOH_MODULE_ROOT . '/Console/Command/TestApiCommand.php');

        $this->assertIsString($api);
        $this->assertIsString($transport);
        $this->assertIsString($command);
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER, false', $api); // preflight: ignore — asserts TLS is not disabled
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER, false', $transport); // preflight: ignore — asserts TLS is not disabled
        $this->assertSame(8, substr_count($api, 'applyTlsVerification('));
        $this->assertStringContainsString('CURLOPT_SSL_VERIFYHOST => 2', $transport);
        $this->assertStringNotContainsString('ObjectManager::getInstance()', $command); // preflight: ignore — asserts the command does not use it
        $this->assertStringContainsString('OrderInterfaceFactory $orderFactory', $command);
    }

    public function testConnectContractIsWired(): void
    {
        $system = file_get_contents(KIYOH_MODULE_ROOT . '/etc/adminhtml/system.xml');
        $routes = file_get_contents(KIYOH_MODULE_ROOT . '/etc/frontend/routes.xml');
        $di = file_get_contents(KIYOH_MODULE_ROOT . '/etc/di.xml');
        $schema = file_get_contents(KIYOH_MODULE_ROOT . '/etc/db_schema.xml');
        $protocol = file_get_contents(KIYOH_MODULE_ROOT . '/Model/Connect/ConnectProtocol.php');
        $uninstall = file_get_contents(KIYOH_MODULE_ROOT . '/Setup/Uninstall.php');

        $this->assertIsString($system);
        $this->assertStringContainsString('Connect with Kiyoh / Klantenvertellen', $system);
        $this->assertStringContainsString('Kiyoh\\Reviews\\Block\\Adminhtml\\System\\Config\\ConnectActions', $system);
        $this->assertIsString($routes);
        $this->assertStringContainsString('frontName="kiyoh"', $routes);
        $this->assertIsString($di);
        $this->assertStringContainsString('CurlConnectTransport', $di);
        $this->assertStringContainsString('ConnectLoginRepository', $di);
        $this->assertIsString($schema);
        $this->assertStringContainsString('kiyoh_reviews_connect_login', $schema);
        $this->assertIsString($protocol);
        $this->assertStringContainsString("CLIENT_ID = 'kiyoh-magento2'", $protocol);
        $this->assertStringContainsString("'/kiyoh/oauth/callback'", $protocol);
        $this->assertFileExists(KIYOH_MODULE_ROOT . '/Model/Connect/ConnectFields.php');
        $this->assertFileExists(KIYOH_MODULE_ROOT . '/Model/Connect/ShopCredential.php');
        $this->assertFileDoesNotExist(KIYOH_MODULE_ROOT . '/Model/Connect/RequestSignature.php');
        $this->assertFileDoesNotExist(KIYOH_MODULE_ROOT . '/Model/Connect/ConnectSecrets.php');
        $this->assertFileDoesNotExist(KIYOH_MODULE_ROOT . '/Model/Connect/MagentoConnectSecrets.php');
        $client = file_get_contents(KIYOH_MODULE_ROOT . '/Model/Connect/ConnectClient.php');
        $service = file_get_contents(KIYOH_MODULE_ROOT . '/Model/Connect/AdminConnectService.php');
        $this->assertIsString($client);
        $this->assertIsString($service);
        $this->assertStringNotContainsString('client_secret', $client);
        $this->assertStringNotContainsString('RequestSignature', $client);
        $this->assertStringNotContainsString('DeploymentConfig', $service);
        $this->assertStringNotContainsString('ConnectSecrets', $service);
        $this->assertIsString($uninstall);
        $this->assertStringContainsString('kiyoh_reviews/%', $uninstall);
        $this->assertStringContainsString('revokeAll', $uninstall);
    }
}
