<?php

namespace Kiyoh\Reviews\Test\Unit\Connect;

use Kiyoh\Reviews\Model\Connect\ConnectHttpResponse;
use Kiyoh\Reviews\Model\Connect\ConnectTransportInterface;

final class FakeConnectTransport implements ConnectTransportInterface
{
    /**
     * @var ConnectHttpResponse[]
     */
    public $responses;

    /**
     * @var array<int, array{method:string,url:string,headers:array<string,string>,body:?string}>
     */
    public $requests = [];

    /**
     * @param ConnectHttpResponse[] $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): ConnectHttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
        if ($this->responses === []) {
            throw new \RuntimeException('no scripted response');
        }

        return array_shift($this->responses);
    }
}
