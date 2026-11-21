<?php

namespace Kiyoh\Reviews\Model\Invitation;

use Magento\Sales\Api\Data\OrderInterface;

final class OrderStatusTransition
{
    /**
     * @param list<string> $triggerStatuses
     */
    public static function enteredTriggerStatus(OrderInterface $order, array $triggerStatuses): bool
    {
        $current = (string) $order->getStatus();
        if (!in_array($current, $triggerStatuses, true)) {
            return false;
        }

        $previous = self::previousStatus($order);
        if ($previous === null || $previous === '') {
            return true;
        }

        return !in_array($previous, $triggerStatuses, true);
    }

    private static function previousStatus(OrderInterface $order): ?string
    {
        if (is_callable([$order, 'getOrigData'])) {
            $orig = $order->getOrigData('status');
            if ($orig !== null && $orig !== '') {
                return (string) $orig;
            }
        }

        return null;
    }

    /**
     * @param string|null $csv Comma-separated trigger statuses from config
     * @return list<string>
     */
    public static function parseTriggerStatuses(?string $csv): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }
}