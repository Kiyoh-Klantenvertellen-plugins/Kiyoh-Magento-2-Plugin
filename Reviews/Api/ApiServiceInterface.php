<?php

namespace Kiyoh\Reviews\Api;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Catalog\Api\Data\ProductInterface;

interface ApiServiceInterface
{
    public function sendShopInvitation(OrderInterface $order): bool;

    public function sendProductInvitation(OrderInterface $order, array $productCodes): bool;

    public function sendShopInvitationWithDetails(OrderInterface $order): array;

    public function sendProductInvitationWithDetails(OrderInterface $order, array $productCodes): array;

    public function syncProduct(ProductInterface $product): bool;

    public function syncProductsBulk(array $products): array;

    public function getShopReviews(int $storeId): ?array;

    public function getProductReviews(string $productCode, int $storeId): ?array;

    public function validateLegacyCredentials(string $server, string $connector, string $companyId): array;

    public function validateNewApiCredentials(string $server, string $hash, string $locationId): array;
}
