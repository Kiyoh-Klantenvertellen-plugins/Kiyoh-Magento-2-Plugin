<?php

namespace Kiyoh\Reviews\Test\Unit\Health;

use Kiyoh\Reviews\Model\Health\HealthTransportInterface;
use Kiyoh\Reviews\Model\Health\HealthTransportResponse;

final class FakeHealthTransport implements HealthTransportInterface
{
    /**
     * @var list<array{url: string, headers: array<string, string>, body: string, timeoutSeconds: int}>
     */
    public $requests = [];

    /**
     * @var callable|null
     */
    public $responder;

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HealthTransportResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeoutSeconds' => $timeoutSeconds,
        ];

        if ($this->responder !== null) {
            return ($this->responder)($url, $headers, $body, $timeoutSeconds);
        }

        return new HealthTransportResponse(200, '{}', null);
    }
}
