<?php

namespace Kiyoh\Reviews\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Catalog\Model\Product\AttributeSet\Options;

class ExcludableAttributeSet implements OptionSourceInterface
{
    protected Options $attributeSetOptions;

    public function __construct(Options $attributeSetOptions)
    {
        $this->attributeSetOptions = $attributeSetOptions;
    }

    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- None (Include All Attribute Sets) --')]
        ];

        foreach ($this->attributeSetOptions->toOptionArray() as $option) {
            $options[] = $option;
        }

        return $options;
    }
}
