<?php

namespace Kiyoh\Reviews\Model\Connect;

interface ConnectTransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): ConnectHttpResponse;
}
