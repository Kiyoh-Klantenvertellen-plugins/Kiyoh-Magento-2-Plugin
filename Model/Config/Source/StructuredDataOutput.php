<?php

namespace Kiyoh\Reviews\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class StructuredDataOutput implements OptionSourceInterface
{
    public const MODE_STANDALONE = 'standalone';

    public const MODE_SKIP_THEME_DUPLICATE = 'skip_when_theme_has_product';

    /**
     * @return list<array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::MODE_STANDALONE,
                'label' => __('Standalone Product JSON-LD (includes aggregateRating)'),
            ],
            [
                'value' => self::MODE_SKIP_THEME_DUPLICATE,
                'label' => __('Do not output (theme or SEO extension already emits Product JSON-LD)'),
            ],
        ];
    }
}