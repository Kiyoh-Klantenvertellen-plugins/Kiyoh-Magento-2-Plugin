<?php

namespace Kiyoh\Reviews\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Catalog\Model\Product\Type as MagentoProductType;

class ExcludableProductType implements OptionSourceInterface
{
    protected MagentoProductType $productType;

    public function __construct(MagentoProductType $productType)
    {
        $this->productType = $productType;
    }

    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- None (Sync All Product Types) --')]
        ];

        foreach ($this->productType->getOptions() as $option) {
            $options[] = $option;
        }

        return $options;
    }
}
