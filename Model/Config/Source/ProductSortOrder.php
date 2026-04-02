<?php

namespace Kiyoh\Reviews\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class ProductSortOrder implements ArrayInterface
{
    /**
     * Return array of product sort order options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'cart_order', 'label' => __('Cart Order (Default)')],
            ['value' => 'price_desc', 'label' => __('Price (High to Low)')],
            ['value' => 'price_asc', 'label' => __('Price (Low to High)')],
            ['value' => 'name_asc', 'label' => __('Name (A to Z)')],
            ['value' => 'name_desc', 'label' => __('Name (Z to A)')],
            ['value' => 'sku_asc', 'label' => __('SKU (A to Z)')],
            ['value' => 'sku_desc', 'label' => __('SKU (Z to A)')]
        ];
    }
}