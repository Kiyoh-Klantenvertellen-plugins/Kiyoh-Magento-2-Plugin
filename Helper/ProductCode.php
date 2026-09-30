<?php

namespace Kiyoh\Reviews\Helper;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Shared product_code sanitization and order-item SKU resolution.
 * Keeps invite product_code values aligned with catalog sync.
 */
class ProductCode
{
    private const MAX_PRODUCT_CODE_LENGTH = 49;
    private const PRODUCT_CODE_HASH_LENGTH = 8;

    /**
     * Sanitise and length-cap a raw SKU into a Kiyoh-safe product_code.
     */
    public function sanitize(?string $sku): string
    {
        $code = (string) $sku;

        $code = str_replace(
            ["\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x92", "\xE2\x88\x92"],
            '-',
            $code
        );

        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $code);
            if ($transliterated !== false) {
                $code = $transliterated;
            }
        }

        $code = str_replace(['"', "'", '`'], '', $code);
        $code = preg_replace('/[^A-Za-z0-9._\-\/]+/', '-', $code);
        $code = preg_replace('/-{2,}/', '-', $code);
        $code = trim((string) $code, "-._/ \t\n\r\0\x0B");

        if (strlen($code) > self::MAX_PRODUCT_CODE_LENGTH) {
            $hash = substr(md5((string) $sku), 0, self::PRODUCT_CODE_HASH_LENGTH);
            $prefixLen = self::MAX_PRODUCT_CODE_LENGTH - self::PRODUCT_CODE_HASH_LENGTH - 1;
            $prefix = rtrim(substr($code, 0, $prefixLen), "-._/");
            $code = $prefix . '-' . $hash;
        }

        return $code;
    }

    /**
     * Resolve the catalog product code for an order item.
     *
     * - Configurable: use purchased simple/child SKU (not parent).
     * - Custom options that append option SKUs to the line SKU: use catalog/simple SKU.
     */
    public function resolveFromOrderItem(OrderItemInterface $item, ?ProductInterface $product = null): string
    {
        $product = $product ?: $item->getProduct();
        $options = $item->getProductOptions() ?: [];
        $catalogSku = $product ? (string) $product->getSku() : '';
        $itemSku = (string) ($item->getSku() ?: '');

        $hasCustomOptions = !empty($options['options']);
        $simpleSku = isset($options['simple_sku']) ? (string) $options['simple_sku'] : '';

        // Configurable (or parent line with a child simple_sku): prefer child SKU.
        if ($item->getProductType() === 'configurable' || $simpleSku !== '') {
            $baseSku = $simpleSku !== '' ? $simpleSku : $itemSku;
            return $this->sanitize($baseSku);
        }

        // Simple/virtual/etc. with custom options that mutated the line SKU → catalog SKU.
        if ($hasCustomOptions && $catalogSku !== '' && $itemSku !== '' && $itemSku !== $catalogSku) {
            return $this->sanitize($catalogSku);
        }

        return $this->sanitize($itemSku !== '' ? $itemSku : $catalogSku);
    }
}
