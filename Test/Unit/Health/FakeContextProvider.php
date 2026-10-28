<?php

namespace Kiyoh\Reviews\Test\Unit\Health;

use Kiyoh\Reviews\Model\Health\HealthContextProviderInterface;
use Kiyoh\Reviews\Model\Health\HealthReportContext;

final class FakeContextProvider implements HealthContextProviderInterface
{
    /**
     * @var array<int, HealthReportContext|null>
     */
    public $contexts = [];

    /**
     * @var list<int>
     */
    public $storeIds = [];

    /**
     * @var list<int>|null
     */
    public $scopeStoreIds;

    /**
     * @var bool
     */
    public $throwOnList = false;

    public function get(int $storeId): ?HealthReportContext
    {
        return $this->contexts[$storeId] ?? null;
    }

    public function listStoreIds(): array
    {
        if ($this->throwOnList) {
            throw new \RuntimeException('store list failed');
        }

        return $this->storeIds;
    }

    public function listStoreIdsForScope(string $websiteCode, string $storeCode): array
    {
        if ($this->throwOnList) {
            throw new \RuntimeException('scope list failed');
        }

        return $this->scopeStoreIds ?? $this->storeIds;
    }
}
