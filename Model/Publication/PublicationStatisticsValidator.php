<?php

namespace Kiyoh\Reviews\Model\Publication;

use Kiyoh\Reviews\Model\Connect\ConnectProtocol;

/**
 * Ensures a successful statistics JSON body matches the selected Server (Kiyoh vs Klantenvertellen).
 */
final class PublicationStatisticsValidator
{
    /**
     * @param array<string, mixed> $data
     */
    public static function tenantMismatchMessage(string $server, array $data): ?string
    {
        if (!isset($data['tenantId'])) {
            return null;
        }
        $raw = $data['tenantId'];
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $tenantId = (int) $raw;
        $expected = ConnectProtocol::expectedTenant($server);
        if ($expected === null) {
            return 'The selected Server value is not supported for credential validation.';
        }
        if ($tenantId === $expected) {
            return null;
        }

        return 'These credentials belong to '
            . self::tenantLabel($tenantId)
            . ', but Server is set to '
            . $server
            . '. Switch Server to '
            . self::serverValueForTenant($tenantId)
            . ' or connect the account for this platform.';
    }

    private static function tenantLabel(int $tenantId): string
    {
        if ($tenantId === ConnectProtocol::TENANT_KIYOH) {
            return 'Kiyoh (kiyoh.com)';
        }
        if ($tenantId === ConnectProtocol::TENANT_KLANTENVERTELLEN) {
            return 'Klantenvertellen (klantenvertellen.nl)';
        }

        return 'tenant ' . $tenantId;
    }

    private static function serverValueForTenant(int $tenantId): string
    {
        if ($tenantId === ConnectProtocol::TENANT_KLANTENVERTELLEN) {
            return 'klantenvertellen.nl';
        }

        return 'kiyoh.com';
    }
}
