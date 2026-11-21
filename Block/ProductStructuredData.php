<?php

namespace Kiyoh\Reviews\Block;

use Kiyoh\Reviews\Helper\ProductCode;
use Kiyoh\Reviews\Model\Config\Source\StructuredDataOutput;
use Kiyoh\Reviews\Model\ProductStructuredData\ProductRatingProvider;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

class ProductStructuredData extends Template
{
    private const CONFIG_PATH_OUTPUT_MODE = 'kiyoh_reviews/product_structured_data/output_mode';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ProductRatingProvider
     */
    private $ratingProvider;

    /**
     * @var ProductCode
     */
    private $productCodeHelper;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var Registry
     */
    private $registry;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        ProductRatingProvider $ratingProvider,
        ProductCode $productCodeHelper,
        Json $json,
        Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->scopeConfig = $scopeConfig;
        $this->ratingProvider = $ratingProvider;
        $this->productCodeHelper = $productCodeHelper;
        $this->json = $json;
        $this->registry = $registry;
    }

    public function shouldRender(): bool
    {
        $storeId = (int) $this->_storeManager->getStore()->getId();

        if (!$this->ratingProvider->isEnabled($storeId)) {
            return false;
        }

        $mode = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_OUTPUT_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($mode === StructuredDataOutput::MODE_SKIP_THEME_DUPLICATE) {
            return false;
        }

        return $this->getJsonLd() !== '';
    }

    public function getJsonLd(): string
    {
        $product = $this->getProduct();
        if (!$product instanceof ProductInterface) {
            return '';
        }

        $storeId = (int) $this->_storeManager->getStore()->getId();
        $productCode = $this->productCodeHelper->sanitize((string) $product->getSku());
        $aggregate = $this->ratingProvider->getAggregate($productCode, $storeId);
        if ($aggregate === null) {
            return '';
        }

        $productUrl = $product->getUrlInStore() ?: $product->getProductUrl();
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) $product->getName(),
            'sku' => $productCode,
            'url' => $productUrl,
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => $aggregate['ratingValue'],
                'reviewCount' => $aggregate['reviewCount'],
                'bestRating' => $aggregate['bestRating'],
                'worstRating' => $aggregate['worstRating'],
            ],
        ];

        return (string) $this->json->serialize($data);
    }

    private function getProduct(): ?ProductInterface
    {
        $product = $this->registry->registry('current_product');

        return $product instanceof ProductInterface ? $product : null;
    }
}