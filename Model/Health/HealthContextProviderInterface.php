<?php

namespace Kiyoh\Reviews\Model\Health;

interface HealthContextProviderInterface
{
    public function get(int $storeId): ?HealthReportContext;

    /**
     * Store views that currently have Kiyoh credentials.
     *
     * @return list<int>
     */
    public function listStoreIds(): array;

    /**
     * Store views affected by a config save. Empty website and store codes mean the default scope.
     *
     * @return list<int>
     */
    public function listStoreIdsForScope(string $websiteCode, string $storeCode): array;
}
