<?php

namespace Kiyoh\Reviews\Model\Health;

interface ShopHealthStoreInterface
{
    public function load(int $storeId): ShopHealthRecord;

    public function save(ShopHealthRecord $record): void;
}
