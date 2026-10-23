<?php

namespace Kiyoh\Reviews\Test\Unit\Health;

use Kiyoh\Reviews\Model\Health\HealthReportContext;
use Kiyoh\Reviews\Model\Health\HealthReporter;
use Kiyoh\Reviews\Model\Health\HealthTransportResponse;
use Kiyoh\Reviews\Model\Health\ShopHealthRecord;
use Kiyoh\Reviews\Model\Health\ShopHealthStoreInterface;
use Kiyoh\Reviews\Model\ModuleMeta;
use PHPUnit\Framework\TestCase;

class HealthReporterTest extends TestCase
{
    /**
     * @var InMemoryShopHealthStore
     */
    private $store;

    /**
     * @var FakeHealthTransport
     */
    private $transport;

    /**
     * @var FakeClock
     */
    private $clock;

    /**
     * @var FakeContextProvider
     */
    private $contexts;

    /**
     * @var FakeHealthLogger
     */
    private $logger;

    /**
     * @var HealthReporter
     */
    private $reporter;

    protected function setUp(): void
    {
        $this->store = new InMemoryShopHealthStore();
        $this->transport = new FakeHealthTransport();
        $this->clock = new FakeClock();
        $this->contexts = new FakeContextProvider();
        $this->logger = new FakeHealthLogger();
        $this->contexts->contexts[1] = $this->context(1);
        $this->contexts->storeIds = [1];
        $this->reporter = new HealthReporter(
            $this->store,
            $this->transport,
            $this->contexts,
            $this->clock,
            $this->logger
        );
    }

    public function testPostsEachMobileUrlWithPublicationHeaders(): void
    {
        $this->contexts->contexts[1] = $this->context(1, [
            'https://kiyoh-mobile.com',
            'https://kiyohmobileui-staging.up.railway.app',
        ]);

        $this->reporter->noteConnection(1, 'ok', null);

        $this->assertCount(2, $this->transport->requests);
        $this->assertSame(
            'https://kiyoh-mobile.com/api/integrations/health',
            $this->transport->requests[0]['url']
        );
        $this->assertSame(
            'https://kiyohmobileui-staging.up.railway.app/api/integrations/health',
            $this->transport->requests[1]['url']
        );
        $this->assertSame(HealthReporter::REQUEST_TIMEOUT_SECONDS, $this->transport->requests[0]['timeoutSeconds']);
        $this->assertSame(2, $this->transport->requests[0]['timeoutSeconds']);

        foreach ($this->transport->requests as $request) {
            $this->assertSame('application/json', $request['headers']['Content-Type']);
            $this->assertSame('token-1', $request['headers']['X-Publication-Api-Token']);
            $this->assertSame('1001', $request['headers']['X-Location-Id']);
            $this->assertStringNotContainsString('token-1', $request['body']);
        }

        $body = $this->decode($this->transport->requests[0]['body']);
        $this->assertSame(
            ['platform', 'shopId', 'pluginVersion', 'connection', 'invites', 'productSync', 'webhooks', 'widget'],
            array_keys($body)
        );
        $this->assertSame('magento2', $body['platform']);
        $this->assertSame('shop.example.com', $body['shopId']);
        $this->assertSame(ModuleMeta::VERSION, $body['pluginVersion']);
        $this->assertSame('ok', $body['connection']['status']);
        $this->assertSame('2026-10-23T08:00:00.000Z', $body['connection']['lastOkAt']);
        $this->assertNull($body['connection']['lastError']);
        $this->assertNull($body['invites']['lastOkAt']);
        $this->assertSame(0, $body['invites']['failures24h']);
        $this->assertArrayNotHasKey('productCodes', $body['productSync']);
        $this->assertNull($body['webhooks']['lastReceivedAt']);
        $this->assertFalse($body['widget']['injected']);
        $this->assertNull($body['widget']['lastCheckedAt']);
        $this->assertTrue($this->logger->containsMessage('health push completed'));
        $this->assertStringNotContainsString('token-1', json_encode($this->logger->entries));
    }

    public function testSkipsUnchangedPayloadUntilTwentyFourHours(): void
    {
        $this->reporter->noteConnection(1, 'ok', null);
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(1, $this->transport->requests);

        $this->clock->advanceSeconds(86399);
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(1, $this->transport->requests);

        $this->clock->advanceSeconds(1);
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(2, $this->transport->requests);
    }

    public function testSendsAgainWhenThePayloadChanges(): void
    {
        $this->reporter->noteConnection(1, 'ok', null);
        $this->reporter->noteInvite(1, true, 200, null);

        $this->assertCount(2, $this->transport->requests);
        $body = $this->decode($this->transport->requests[1]['body']);
        $this->assertSame('2026-10-23T08:00:00.000Z', $body['invites']['lastOkAt']);
        $this->assertSame(0, $body['invites']['failures24h']);
        $this->assertSame('ok', $body['connection']['status']);
    }

    public function testInviteFailuresRollOffAfterTwentyFourHours(): void
    {
        $this->reporter->noteInvite(1, false, 401, 'unauthorized');
        $this->reporter->noteInvite(1, false, 500, 'busy');
        $body = $this->decode($this->transport->requests[1]['body']);
        $this->assertSame(2, $body['invites']['failures24h']);
        $this->assertNull($body['invites']['lastOkAt']);
        $this->assertSame('error', $body['connection']['status']);
        $this->assertSame('unauthorized', $body['connection']['lastError']);

        $this->reporter->noteInvite(1, true, 200, null);
        $body = $this->decode($this->transport->requests[2]['body']);
        $this->assertSame(2, $body['invites']['failures24h']);
        $this->assertSame('ok', $body['connection']['status']);
        $this->assertNull($body['connection']['lastError']);
        $this->assertNotNull($body['invites']['lastOkAt']);

        $this->clock->advanceSeconds(86401);
        $this->reporter->publishDueHeartbeats();
        $body = $this->decode($this->transport->requests[3]['body']);
        $this->assertSame(0, $body['invites']['failures24h']);
    }

    public function testProductSyncKeepsFiveHundredNewestCodesAndDropsInvalidOnes(): void
    {
        $codes = ['  SKU-1  ', '', str_repeat('x', 201), str_repeat('y', 200), 'SKU-ä'];
        for ($i = 0; $i < 501; $i++) {
            $codes[] = 'SKU-' . $i;
        }

        $this->reporter->noteProductSync(1, false, 403, str_repeat('e', 600), []);
        $failed = $this->decode($this->transport->requests[0]['body']);
        $this->assertNull($failed['productSync']['lastOkAt']);
        $this->assertSame(str_repeat('e', 500), $failed['productSync']['lastError']);
        $this->assertSame('error', $failed['connection']['status']);
        $this->assertSame('forbidden', $failed['connection']['lastError']);
        $this->assertArrayNotHasKey('productCodes', $failed['productSync']);

        $this->reporter->noteProductSync(1, true, 200, null, $codes);
        $synced = $this->decode($this->transport->requests[1]['body']);
        $this->assertSame('2026-10-23T08:00:00.000Z', $synced['productSync']['lastOkAt']);
        $this->assertNull($synced['productSync']['lastError']);
        $this->assertSame('ok', $synced['connection']['status']);
        $this->assertCount(500, $synced['productSync']['productCodes']);
        $this->assertSame('SKU-1', $synced['productSync']['productCodes'][0]);
        $this->assertNotContains('SKU-500', $synced['productSync']['productCodes']);
        $this->assertNotContains(str_repeat('x', 201), $synced['productSync']['productCodes']);
        $this->assertContains(str_repeat('y', 200), $synced['productSync']['productCodes']);
        $this->assertContains('SKU-ä', $synced['productSync']['productCodes']);
        $this->assertSame(1, count(array_keys($synced['productSync']['productCodes'], 'SKU-1', true)));
        $this->assertStringContainsString('SKU-ä', $this->transport->requests[1]['body']);
    }

    public function testSuccessfulHttpDoesNotTreatInviteRejectionAsABadConnection(): void
    {
        $this->reporter->noteInvite(1, false, 200, 'INVALID_PRODUCT_CODE');
        $body = $this->decode($this->transport->requests[0]['body']);

        $this->assertSame('ok', $body['connection']['status']);
        $this->assertNull($body['invites']['lastOkAt']);
        $this->assertSame(1, $body['invites']['failures24h']);
    }

    public function testRateLimitUsesRetryAfterAndBlocksUntilItElapses(): void
    {
        $this->transport->responder = function () {
            return new HealthTransportResponse(
                429,
                '{"error":"rate_limited","retryAfterSeconds":12}',
                null
            );
        };

        $this->reporter->noteConnection(1, 'ok', null);
        $this->reporter->noteInvite(1, true, 200, null);
        $this->assertCount(1, $this->transport->requests);
        $this->assertTrue($this->logger->containsMessage('health push failed'));

        $this->clock->advanceSeconds(11);
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(1, $this->transport->requests);

        $this->clock->advanceSeconds(1);
        $this->transport->responder = null;
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(2, $this->transport->requests);
        $this->assertTrue($this->logger->containsMessage('health push completed'));
    }

    public function testRetryAfterHeaderWinsOverTheBodyAndIsCapped(): void
    {
        $this->transport->responder = function () {
            return new HealthTransportResponse(429, '{"retryAfterSeconds":90}', '5');
        };
        $this->reporter->noteConnection(1, 'ok', null);
        $this->clock->advanceSeconds(5);
        $this->transport->responder = null;
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(2, $this->transport->requests);

        $this->transport->requests = [];
        $this->transport->responder = function () {
            return new HealthTransportResponse(429, '{"retryAfterSeconds":99999}', null);
        };
        $this->clock->advanceSeconds(1);
        $this->reporter->noteInvite(1, false, 500, 'again');
        $this->clock->advanceSeconds(3599);
        $this->reporter->publishDueHeartbeats();
        $sentAtCapStart = count($this->transport->requests);
        $this->clock->advanceSeconds(1);
        $this->transport->responder = null;
        $this->reporter->publishDueHeartbeats();
        $this->assertSame($sentAtCapStart, count($this->transport->requests) - 1);
    }

    public function testHttpDateRetryAfterUsesTheReporterClock(): void
    {
        $this->transport->responder = function () {
            return new HealthTransportResponse(429, '{}', 'Fri, 23 Oct 2026 08:00:30 GMT');
        };
        $this->reporter->noteConnection(1, 'ok', null);
        $this->clock->advanceSeconds(29);
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(1, $this->transport->requests);

        $this->clock->advanceSeconds(1);
        $this->transport->responder = null;
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(2, $this->transport->requests);
    }

    public function testZeroRetryAfterWaitsOneSecond(): void
    {
        $this->transport->responder = function () {
            return new HealthTransportResponse(429, '{"retryAfterSeconds":0}', null);
        };
        $this->reporter->noteConnection(1, 'ok', null);
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(1, $this->transport->requests);

        $this->clock->advanceSeconds(1);
        $this->transport->responder = null;
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(2, $this->transport->requests);
    }

    public function testFailedUrlDoesNotCountAsReported(): void
    {
        $this->transport->responder = function () {
            return new HealthTransportResponse(500, '{}', null);
        };
        $this->reporter->noteConnection(1, 'ok', null);
        $this->transport->responder = null;
        $this->reporter->publishDueHeartbeats();

        $this->assertCount(2, $this->transport->requests);
        $this->assertTrue($this->logger->containsMessage('health push failed'));
    }

    public function testOneRejectedUrlKeepsTheOtherUrlRetrying(): void
    {
        $this->contexts->contexts[1] = $this->context(1, [
            'https://kiyoh-mobile.com',
            'https://kiyohmobileui-staging.up.railway.app',
        ]);
        $this->transport->responder = function (string $url) {
            if (strpos($url, 'staging') !== false) {
                return new HealthTransportResponse(503, '{}', null);
            }

            return new HealthTransportResponse(200, '{}', null);
        };

        $this->reporter->noteConnection(1, 'ok', null);
        $this->assertCount(2, $this->transport->requests);

        $this->reporter->publishDueHeartbeats();
        $this->assertCount(4, $this->transport->requests);
    }

    public function testTransportAndStoreFailuresDoNotEscape(): void
    {
        $this->transport->responder = function () {
            throw new \RuntimeException('timed out');
        };
        $this->reporter->noteInvite(1, true, 200, null);
        $this->assertCount(1, $this->transport->requests);
        $this->assertTrue($this->logger->containsMessage('health push threw'));

        $this->transport->responder = null;
        $this->reporter->publishDueHeartbeats();
        $this->assertCount(2, $this->transport->requests);

        $throwingStore = new class implements ShopHealthStoreInterface {
            public function load(int $storeId): ShopHealthRecord
            {
                throw new \RuntimeException('db down');
            }

            public function save(ShopHealthRecord $record): void
            {
                throw new \RuntimeException('db down');
            }
        };
        $reporter = new HealthReporter(
            $throwingStore,
            $this->transport,
            $this->contexts,
            $this->clock,
            $this->logger
        );
        $before = count($this->transport->requests);
        $reporter->noteInvite(1, false, 500, 'nope');
        $reporter->noteProductSync(1, false, 500, 'nope', ['SKU']);
        $reporter->publishDueHeartbeats();
        $this->assertSame($before, count($this->transport->requests));
        $this->assertTrue($this->logger->containsMessage('health update failed'));
    }

    public function testMissingCredentialsAreSkipped(): void
    {
        $this->contexts->contexts = [];
        $this->contexts->storeIds = [];
        $this->reporter->noteConnection(1, 'ok', null);
        $this->reporter->publishDueHeartbeats();

        $this->assertSame([], $this->transport->requests);
        $this->assertSame('ok', $this->store->load(1)->connectionStatus);
    }

    public function testCatalogWideSyncFansOutToEveryConfiguredStoreView(): void
    {
        $this->contexts->contexts[2] = $this->context(2);
        $this->contexts->storeIds = [1, 2];

        $this->reporter->noteProductSync(0, true, 200, null, ['SKU-1']);

        $this->assertCount(2, $this->transport->requests);
        $shopIds = [
            $this->decode($this->transport->requests[0]['body'])['shopId'],
            $this->decode($this->transport->requests[1]['body'])['shopId'],
        ];
        $this->assertEqualsCanonicalizing(['shop.example.com', 'shop.example.com/2'], $shopIds);
        foreach ($this->transport->requests as $request) {
            $body = $this->decode($request['body']);
            $this->assertSame(['SKU-1'], $body['productSync']['productCodes']);
        }
    }

    public function testDeferredOrderFlowSendsOnceWithTheFinalState(): void
    {
        $this->reporter->deferPublishing();
        $this->reporter->deferPublishing();
        $this->reporter->noteProductSync(1, true, 200, null, ['SKU-A']);
        $this->reporter->flushDeferred();
        $this->assertSame([], $this->transport->requests);

        $this->reporter->noteProductSync(1, true, 200, null, ['SKU-B']);
        $this->reporter->noteInvite(1, false, 500, 'first');
        $this->reporter->noteInvite(1, true, 200, null);
        $this->assertSame([], $this->transport->requests);

        $this->reporter->flushDeferred();
        $this->assertCount(1, $this->transport->requests);
        $body = $this->decode($this->transport->requests[0]['body']);
        $this->assertSame(['SKU-B', 'SKU-A'], $body['productSync']['productCodes']);
        $this->assertSame(1, $body['invites']['failures24h']);
        $this->assertSame('2026-10-23T08:00:00.000Z', $body['invites']['lastOkAt']);
        $this->assertSame('ok', $body['connection']['status']);
    }

    public function testConfigSaveReportsEachStoreViewInScope(): void
    {
        $this->contexts->contexts[2] = $this->context(2);
        $this->contexts->scopeStoreIds = [1, 2];

        $this->reporter->noteConfigSaved('base', 'nl');
        $this->reporter->noteConfigSaved('base', 'nl');

        $this->assertCount(2, $this->transport->requests);
        $this->assertSame('ok', $this->decode($this->transport->requests[0]['body'])['connection']['status']);
        $this->assertSame('ok', $this->decode($this->transport->requests[1]['body'])['connection']['status']);
    }

    public function testHeartbeatContinuesAfterOneStoreFails(): void
    {
        $this->contexts->contexts[2] = $this->context(2);
        $this->contexts->storeIds = [1, 2];
        $this->transport->responder = function (string $url, array $headers) {
            if ($headers['X-Location-Id'] === '1001') {
                throw new \RuntimeException('down');
            }

            return new HealthTransportResponse(200, '{}', null);
        };

        $this->reporter->publishDueHeartbeats();

        $this->assertCount(2, $this->transport->requests);
        $this->assertSame('1002', $this->transport->requests[1]['headers']['X-Location-Id']);
        $this->assertTrue($this->logger->containsMessage('health push threw'));
        $this->assertTrue($this->logger->containsMessage('health push completed'));
    }

    public function testListingStoresOrTheConfigScopeCanFailClosed(): void
    {
        $this->contexts->throwOnList = true;
        $this->reporter->publishDueHeartbeats();
        $this->reporter->noteConfigSaved('', '');
        $this->reporter->noteProductSync(0, true, 200, null, ['SKU-1']);

        $this->assertSame([], $this->transport->requests);
        $this->assertTrue($this->logger->containsMessage('health heartbeat failed'));
        $this->assertTrue($this->logger->containsMessage('health config save failed'));
    }

    public function testUnknownConnectionStatusAndBlankErrorsAreNormalized(): void
    {
        $record = ShopHealthRecord::empty(1);
        $record->connectionStatus = 'bogus';
        $record->connectionLastError = '   ';
        $this->store->save($record);

        $this->reporter->publishDueHeartbeats();
        $body = $this->decode($this->transport->requests[0]['body']);

        $this->assertSame('unknown', $body['connection']['status']);
        $this->assertNull($body['connection']['lastError']);
    }

    /**
     * @param list<string>|null $urls
     */
    private function context(int $storeId, ?array $urls = null): HealthReportContext
    {
        return new HealthReportContext(
            $storeId,
            $storeId === 1 ? 'shop.example.com' : 'shop.example.com/' . $storeId,
            (string) (1000 + $storeId),
            'token-' . $storeId,
            $urls ?? ['https://kiyoh-mobile.com'],
            ModuleMeta::VERSION
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
