<?php

namespace Kiyoh\Reviews\Model\Health;

interface HealthTransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HealthTransportResponse;
}
