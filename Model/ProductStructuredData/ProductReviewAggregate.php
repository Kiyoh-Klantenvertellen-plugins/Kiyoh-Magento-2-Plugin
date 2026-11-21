<?php

namespace Kiyoh\Reviews\Model\ProductStructuredData;

/**
 * Maps Kiyoh publication product review API payloads to schema.org aggregateRating fields.
 */
final class ProductReviewAggregate
{
    public const BEST_RATING = 10;
    public const WORST_RATING = 1;

    /**
     * @param array<string, mixed>|null $payload
     * @return array{ratingValue: float, reviewCount: int, bestRating: int, worstRating: int}|null
     */
    public static function fromApiPayload(?array $payload): ?array
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $rating = $payload['averageRating'] ?? $payload['average_rating'] ?? null;
        $count = $payload['numberReviews'] ?? $payload['numberOfReviews'] ?? null;

        if ($count === null && isset($payload['reviews']) && is_array($payload['reviews'])) {
            $count = count($payload['reviews']);
        }

        if ($rating === null || $count === null) {
            return null;
        }

        $reviewCount = (int) $count;
        if ($reviewCount <= 0) {
            return null;
        }

        $ratingValue = (float) $rating;
        if ($ratingValue <= 0) {
            return null;
        }

        return [
            'ratingValue' => $ratingValue,
            'reviewCount' => $reviewCount,
            'bestRating' => self::BEST_RATING,
            'worstRating' => self::WORST_RATING,
        ];
    }
}