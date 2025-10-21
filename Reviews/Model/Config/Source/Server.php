<?php

namespace Kiyoh\Reviews\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class Server implements ArrayInterface
{
    /**
     * Return array of server options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'kiyoh.com', 'label' => __('Kiyoh International (kiyoh.com)')],
            ['value' => 'klantenvertellen.nl', 'label' => __('Klantenvertellen (klantenvertellen.nl)')]
        ];
    }
}