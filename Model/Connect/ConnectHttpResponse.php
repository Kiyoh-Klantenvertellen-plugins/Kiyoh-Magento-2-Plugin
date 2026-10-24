<?php

namespace Kiyoh\Reviews\Model\Connect;

final class ConnectHttpResponse
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
     * @var string|null
     */
    public $retryAfterHeader;

    public function __construct(int $status, string $body, ?string $retryAfterHeader = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->retryAfterHeader = $retryAfterHeader;
    }
}
