<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * Picks the secure base URL for the scope being connected.
 * A store view uses its own row, then its website, then default.
 * Another store view's row is never used.
 */
final class ScopeBaseUrl
{
    /**
     * @param array<int, array{scope:string,scope_id:int,value:string}> $rows
     */
    public static function resolve(array $rows, string $scopeType, int $scopeId, ?int $websiteId): ?string
    {
        $indexed = [];
        foreach ($rows as $row) {
            $value = trim($row['value']);
            if ($value === '' || strpos($value, '{{') !== false) {
                continue;
            }
            $indexed[$row['scope'] . ':' . $row['scope_id']] = $value;
        }

        $keys = [];
        if ($scopeType === 'stores') {
            $keys[] = 'stores:' . $scopeId;
            if ($websiteId !== null) {
                $keys[] = 'websites:' . $websiteId;
            }
        } elseif ($scopeType === 'websites') {
            $keys[] = 'websites:' . $scopeId;
        }
        $keys[] = 'default:0';

        foreach ($keys as $key) {
            if (isset($indexed[$key])) {
                return $indexed[$key];
            }
        }

        return null;
    }
}
