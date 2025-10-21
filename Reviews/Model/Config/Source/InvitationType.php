<?php

namespace Kiyoh\Reviews\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class InvitationType implements ArrayInterface
{
    /**
     * Return array of invitation type options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'shop_and_product', 'label' => __('Shop + Product Reviews')],
            ['value' => 'product_only', 'label' => __('Product Reviews Only')]
        ];
    }
}