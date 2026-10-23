<?php

namespace Kiyoh\Reviews\Model\Health;

/**
 * Posts per-store-view health to each configured Kiyoh Mobile base URL.
 *
 * A report is sent when the payload changes, and again when the last accepted
 * report is at least 24 hours old. HTTP 429 delays that URL until Retry-After.
 * Failures are logged and never thrown, so order processing keeps running.
 */
class HealthReporter
{
    public const PLATFORM = 'magento2';
    public const HEALTH_PATH = '/api/integrations/health';
    public const REPORT_MAX_AGE_SECONDS = 86400;
    public const REQUEST_TIMEOUT_SECONDS = 2;
    public const MAX_PRODUCT_CODES = 500;
    public const MAX_ERROR_LENGTH = 500;
    public const MAX_FAILURES_TRACKED = 1000;
    public const MAX_RETRY_AFTER_SECONDS = 3600;

    /**
     * @var ShopHealthStoreInterface
     */
    private $store;

    /**
     * @var HealthTransportInterface
     */
    private $transport;

    /**
     * @var HealthContextProviderInterface
     */
    private $contexts;

    /**
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var HealthLoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $deferDepth = 0;

    /**
     * @var array<int, bool>
     */
    private $deferredStoreIds = [];

    public function __construct(
        ShopHealthStoreInterface $store,
        HealthTransportInterface $transport,
        HealthContextProviderInterface $contexts,
        ClockInterface $clock,
        HealthLoggerInterface $logger
    ) {
        $this->store = $store;
        $this->transport = $transport;
        $this->contexts = $contexts;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /**
     * Hold HTTP posts until flushDeferred(). State is still saved immediately.
     * Used around an order so product sync and the invitation share one request.
     */
    public function deferPublishing(): void
    {
        $this->deferDepth++;
    }

    public function flushDeferred(): void
    {
        try {
            if ($this->deferDepth > 0) {
                $this->deferDepth--;
            }
            if ($this->deferDepth > 0) {
                return;
            }

            $storeIds = array_keys($this->deferredStoreIds);
            $this->deferredStoreIds = [];
            foreach ($storeIds as $storeId) {
                $this->publish((int) $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('health flush failed', ['error' => $e->getMessage()]);
        }
    }

    public function noteInvite(int $storeId, bool $success, ?int $httpStatus, ?string $errorMessage): void
    {
        $this->guard(function () use ($storeId, $success, $httpStatus, $errorMessage) {
            $record = $this->store->load($storeId);
            $now = $this->clock->now();
            if ($success) {
                $record->invitesLastOkAt = $this->formatTime($now);
            } else {
                $this->rememberFailure($record, $now);
            }
            $this->applyHttpStatus($record, $httpStatus, $now);
            $this->store->save($record);
            $this->queueOrPublish($storeId);
        });
    }

    /**
     * @param list<string> $productCodes Codes synced by this attempt. Capped at 500.
     */
    public function noteProductSync(
        int $storeId,
        bool $success,
        ?int $httpStatus,
        ?string $errorMessage,
        array $productCodes
    ): void {
        $this->guard(function () use ($storeId, $success, $httpStatus, $errorMessage, $productCodes) {
            foreach ($this->targetStoreIds($storeId) as $targetStoreId) {
                $record = $this->store->load($targetStoreId);
                $now = $this->clock->now();
                if ($success) {
                    $record->productSyncLastOkAt = $this->formatTime($now);
                    $record->productSyncLastError = null;
                    $record->productCodes = $this->mergeProductCodes($record->productCodes, $productCodes);
                } else {
                    $record->productSyncLastError = $this->boundError($errorMessage, 'product sync failed');
                }
                $this->applyHttpStatus($record, $httpStatus, $now);
                $this->store->save($record);
                $this->queueOrPublish($targetStoreId);
            }
        });
    }

    public function noteConnection(int $storeId, string $status, ?string $errorMessage): void
    {
        $this->guard(function () use ($storeId, $status, $errorMessage) {
            $record = $this->store->load($storeId);
            $now = $this->clock->now();
            $record->connectionStatus = $this->normalizeStatus($status);
            if ($record->connectionStatus === 'ok') {
                $record->connectionLastOkAt = $this->formatTime($now);
                $record->connectionLastError = null;
            } elseif ($record->connectionStatus === 'error') {
                $record->connectionLastError = $this->boundError($errorMessage, 'error');
            }
            $this->store->save($record);
            $this->queueOrPublish($storeId);
        });
    }

    /**
     * Config was saved for this scope. Each store view with credentials is reported
     * with connection status ok, because the save completed with those credentials.
     */
    public function noteConfigSaved(string $websiteCode, string $storeCode): void
    {
        try {
            foreach ($this->contexts->listStoreIdsForScope($websiteCode, $storeCode) as $storeId) {
                $this->noteConnection($storeId, 'ok', null);
            }
        } catch (\Throwable $e) {
            $this->logger->error('health config save failed', ['error' => $e->getMessage()]);
        }
    }

    public function publishDueHeartbeats(): void
    {
        try {
            foreach ($this->contexts->listStoreIds() as $storeId) {
                $this->publish($storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('health heartbeat failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param callable():void $operation
     */
    private function guard(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->logger->error('health update failed', ['error' => $e->getMessage()]);
        }
    }

    private function queueOrPublish(int $storeId): void
    {
        if ($this->deferDepth > 0) {
            $this->deferredStoreIds[$storeId] = true;
            return;
        }

        $this->publish($storeId);
    }

    /**
     * A catalog-wide sync has no store view. Report it for every configured view.
     *
     * @return list<int>
     */
    private function targetStoreIds(int $storeId): array
    {
        if ($storeId > 0) {
            return [$storeId];
        }

        return $this->contexts->listStoreIds();
    }

    private function publish(int $storeId): void
    {
        try {
            $this->publishStore($storeId);
        } catch (\Throwable $e) {
            $this->logger->error('health push threw', [
                'storeId' => $storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function publishStore(int $storeId): void
    {
        $context = $this->contexts->get($storeId);
        if (
            $context === null
            || $context->shopId === ''
            || $context->apiToken === ''
            || $context->locationId === ''
            || $context->mobileBaseUrls === []
        ) {
            return;
        }

        $record = $this->store->load($storeId);
        $now = $this->clock->now();
        $body = $this->buildBody($context, $record, $now);
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $this->logger->error('health payload could not be encoded', ['storeId' => $storeId]);
            return;
        }

        $digest = hash('sha256', $json);
        if ($this->reportedRecently($record, $digest, $now)) {
            return;
        }

        $allAccepted = true;
        $attempted = false;
        foreach ($context->mobileBaseUrls as $baseUrl) {
            $until = $record->rateLimitedUntil[$baseUrl] ?? 0;
            if ($until > $now->getTimestamp()) {
                $allAccepted = false;
                $this->logger->warning('health push skipped because of rate limit', [
                    'storeId' => $storeId,
                    'shopId' => $context->shopId,
                    'mobileBase' => $baseUrl,
                ]);
                continue;
            }

            $attempted = true;
            if (!$this->postOne($baseUrl, $context, $json, $record, $now)) {
                $allAccepted = false;
            }
        }

        $this->forgetExpiredRateLimits($record, $now);
        if ($allAccepted && $attempted) {
            $record->lastReportedDigest = $digest;
            $record->lastReportedAt = $this->formatTime($now);
            $this->logger->info('health push completed', [
                'storeId' => $storeId,
                'shopId' => $context->shopId,
            ]);
        }

        $this->store->save($record);
    }

    private function postOne(
        string $baseUrl,
        HealthReportContext $context,
        string $json,
        ShopHealthRecord $record,
        \DateTimeImmutable $now
    ): bool {
        $url = rtrim($baseUrl, '/') . self::HEALTH_PATH;
        try {
            $response = $this->transport->post(
                $url,
                [
                    'Content-Type' => 'application/json',
                    'X-Publication-Api-Token' => $context->apiToken,
                    'X-Location-Id' => $context->locationId,
                ],
                $json,
                self::REQUEST_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            $this->logger->error('health push threw', [
                'storeId' => $context->storeId,
                'shopId' => $context->shopId,
                'mobileBase' => $baseUrl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($response->status === 429) {
            $retryAfter = $this->retryAfterSeconds($response, $now);
            $record->rateLimitedUntil[$baseUrl] = $now->getTimestamp() + $retryAfter;
            $this->logger->warning('health push failed', [
                'storeId' => $context->storeId,
                'shopId' => $context->shopId,
                'mobileBase' => $baseUrl,
                'httpStatus' => 429,
                'retryAfterSeconds' => $retryAfter,
            ]);
            return false;
        }

        if ($response->status < 200 || $response->status >= 300) {
            $this->logger->warning('health push failed', [
                'storeId' => $context->storeId,
                'shopId' => $context->shopId,
                'mobileBase' => $baseUrl,
                'httpStatus' => $response->status,
            ]);
            return false;
        }

        unset($record->rateLimitedUntil[$baseUrl]);
        return true;
    }

    private function retryAfterSeconds(HealthTransportResponse $response, \DateTimeImmutable $now): int
    {
        $seconds = null;
        $header = $response->retryAfterHeader;
        if (is_string($header) && $header !== '') {
            if (preg_match('/^\d+$/', $header)) {
                $seconds = (int) $header;
            } else {
                try {
                    $when = new \DateTimeImmutable($header);
                    $seconds = $when->getTimestamp() - $now->getTimestamp();
                } catch (\Exception $e) {
                    $seconds = null;
                }
            }
        }

        if ($seconds === null) {
            $decoded = json_decode($response->body, true);
            if (is_array($decoded) && isset($decoded['retryAfterSeconds']) && is_numeric($decoded['retryAfterSeconds'])) {
                $seconds = (int) $decoded['retryAfterSeconds'];
            }
        }

        if ($seconds === null) {
            $seconds = 60;
        }
        if ($seconds < 1) {
            $seconds = 1;
        }
        if ($seconds > self::MAX_RETRY_AFTER_SECONDS) {
            $seconds = self::MAX_RETRY_AFTER_SECONDS;
        }

        return $seconds;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBody(HealthReportContext $context, ShopHealthRecord $record, \DateTimeImmutable $now): array
    {
        $productSync = [
            'lastOkAt' => $record->productSyncLastOkAt,
            'lastError' => $this->nullableError($record->productSyncLastError),
        ];
        $productCodes = $this->payloadProductCodes($record->productCodes);
        if ($productCodes !== []) {
            $productSync['productCodes'] = $productCodes;
        }

        return [
            'platform' => self::PLATFORM,
            'shopId' => $context->shopId,
            'pluginVersion' => $context->pluginVersion,
            'connection' => [
                'status' => $this->normalizeStatus($record->connectionStatus),
                'lastOkAt' => $record->connectionLastOkAt,
                'lastError' => $this->nullableError($record->connectionLastError),
            ],
            'invites' => [
                'lastOkAt' => $record->invitesLastOkAt,
                'failures24h' => $this->failureCount($record, $now),
            ],
            'productSync' => $productSync,
            'webhooks' => [
                'lastReceivedAt' => null,
            ],
            'widget' => [
                'injected' => false,
                'lastCheckedAt' => null,
            ],
        ];
    }

    private function applyHttpStatus(ShopHealthRecord $record, ?int $httpStatus, \DateTimeImmutable $now): void
    {
        if ($httpStatus === null) {
            return;
        }
        if ($httpStatus === 401) {
            $record->connectionStatus = 'error';
            $record->connectionLastError = 'unauthorized';
            return;
        }
        if ($httpStatus === 403) {
            $record->connectionStatus = 'error';
            $record->connectionLastError = 'forbidden';
            return;
        }
        if ($httpStatus >= 200 && $httpStatus < 300) {
            $record->connectionStatus = 'ok';
            $record->connectionLastOkAt = $this->formatTime($now);
            $record->connectionLastError = null;
        }
    }

    private function rememberFailure(ShopHealthRecord $record, \DateTimeImmutable $now): void
    {
        $record->inviteFailureAts[] = $this->formatTime($now);
        $cutoff = $now->getTimestamp() - self::REPORT_MAX_AGE_SECONDS;
        $kept = [];
        foreach ($record->inviteFailureAts as $at) {
            $parsed = $this->parseTime($at);
            if ($parsed !== null && $parsed->getTimestamp() >= $cutoff) {
                $kept[] = $this->formatTime($parsed);
            }
        }
        if (count($kept) > self::MAX_FAILURES_TRACKED) {
            $kept = array_slice($kept, -self::MAX_FAILURES_TRACKED);
        }
        $record->inviteFailureAts = array_values($kept);
    }

    private function failureCount(ShopHealthRecord $record, \DateTimeImmutable $now): int
    {
        $cutoff = $now->getTimestamp() - self::REPORT_MAX_AGE_SECONDS;
        $count = 0;
        foreach ($record->inviteFailureAts as $at) {
            $parsed = $this->parseTime($at);
            if ($parsed !== null && $parsed->getTimestamp() >= $cutoff) {
                $count++;
            }
        }
        if ($count > 1000000) {
            return 1000000;
        }

        return $count;
    }

    /**
     * @param list<string> $existing
     * @param list<string> $incoming
     * @return list<string>
     */
    private function mergeProductCodes(array $existing, array $incoming): array
    {
        return $this->payloadProductCodes(array_merge($incoming, $existing));
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function payloadProductCodes(array $codes): array
    {
        $clean = [];
        foreach ($codes as $code) {
            if (!is_string($code)) {
                continue;
            }
            $code = trim($code);
            if ($code === '' || $this->stringLength($code) > 200 || !$this->isUtf8($code)) {
                continue;
            }
            if (isset($clean[$code])) {
                continue;
            }
            $clean[$code] = true;
            if (count($clean) >= self::MAX_PRODUCT_CODES) {
                break;
            }
        }

        return array_keys($clean);
    }

    private function reportedRecently(ShopHealthRecord $record, string $digest, \DateTimeImmutable $now): bool
    {
        if ($record->lastReportedDigest !== $digest || $record->lastReportedAt === null) {
            return false;
        }

        $reportedAt = $this->parseTime($record->lastReportedAt);
        if ($reportedAt === null) {
            return false;
        }

        return ($now->getTimestamp() - $reportedAt->getTimestamp()) < self::REPORT_MAX_AGE_SECONDS;
    }

    private function forgetExpiredRateLimits(ShopHealthRecord $record, \DateTimeImmutable $now): void
    {
        foreach ($record->rateLimitedUntil as $url => $until) {
            if ($until <= $now->getTimestamp()) {
                unset($record->rateLimitedUntil[$url]);
            }
        }
    }

    private function normalizeStatus(string $status): string
    {
        if ($status === 'ok' || $status === 'error' || $status === 'unknown') {
            return $status;
        }

        return 'unknown';
    }

    private function nullableError(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $bounded = $this->boundError($message, '');
        return $bounded === '' ? null : $bounded;
    }

    private function boundError(?string $message, string $fallback): string
    {
        $message = str_replace(["\r", "\n", "\t"], ' ', (string) $message);
        $message = trim($message);
        if ($message === '' || !$this->isUtf8($message)) {
            $message = $fallback;
        }
        if ($this->stringLength($message) > self::MAX_ERROR_LENGTH) {
            $message = function_exists('mb_substr')
                ? mb_substr($message, 0, self::MAX_ERROR_LENGTH)
                : substr($message, 0, self::MAX_ERROR_LENGTH);
        }

        return $message;
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function isUtf8(string $value): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($value, 'UTF-8');
        }

        return preg_match('//u', $value) === 1;
    }

    private function formatTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function parseTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}
