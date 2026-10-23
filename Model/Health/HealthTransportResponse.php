<?php

namespace Kiyoh\Reviews\Model\Health;

class HealthTransportResponse
{
    /**
     * @var int
     */
    public $status;

    /**
     * @var string
     */
    public $body;

    /**
     * @var string|null Raw Retry-After header value, when the server sent one.
     */
    public $retryAfterHeader;

    public function __construct(int $status, string $body, ?string $retryAfterHeader = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->retryAfterHeader = $retryAfterHeader;
    }
}
