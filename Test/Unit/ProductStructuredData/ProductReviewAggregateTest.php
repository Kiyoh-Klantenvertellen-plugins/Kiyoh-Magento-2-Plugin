<?php

namespace Kiyoh\Reviews\Test\Unit\ProductStructuredData;

use Kiyoh\Reviews\Model\ProductStructuredData\ProductReviewAggregate;
use PHPUnit\Framework\TestCase;

class ProductReviewAggregateTest extends TestCase
{
    public function testMapsAverageAndCount(): void
    {
        $result = ProductReviewAggregate::fromApiPayload([
            'averageRating' => 8.6,
            'numberReviews' => 12,
        ]);

        $this->assertNotNull($result);
        $this->assertSame(8.6, $result['ratingValue']);
        $this->assertSame(12, $result['reviewCount']);
        $this->assertSame(10, $result['bestRating']);
        $this->assertSame(1, $result['worstRating']);
    }

    public function testReturnsNullWhenNoReviews(): void
    {
        $this->assertNull(ProductReviewAggregate::fromApiPayload(['averageRating' => 9, 'numberReviews' => 0]));
    }
}