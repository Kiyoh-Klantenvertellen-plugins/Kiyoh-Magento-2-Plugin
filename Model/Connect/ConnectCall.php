<?php

namespace Kiyoh\Reviews\Model\Connect;

final class ConnectCall
{
    /**
     * @var bool
     */
    public $ok;

    /**
     * @var int
     */
    public $status;

    /**
     * @var string Admin-facing text. No secrets.
     */
    public $message;

    /**
     * @var int|null
     */
    public $retryAfterSeconds;

    /**
     * @var string|null
     */
    public $accessToken;

    /**
     * @var string|null
     */
    public $refreshToken;

    /**
     * @var int|null
     */
    public $expiresIn;

    /**
     * @var CredentialSet|null
     */
    public $credentials;

    /**
     * @var string Detail for the server log only.
     */
    public $logDetail;

    /**
     * @var string Response body for the adapter. Never show this to a merchant.
     */
    public $rawBody;

    /**
     * @var ShopCredential|null One-time credential from POST /shops/register.
     */
    public $shopCredential;

    private function __construct(
        bool $ok,
        int $status,
        string $message,
        ?int $retryAfterSeconds,
        ?string $accessToken,
        ?string $refreshToken,
        ?int $expiresIn,
        ?CredentialSet $credentials,
        string $logDetail,
        string $rawBody,
        ?ShopCredential $shopCredential
    ) {
        $this->ok = $ok;
        $this->status = $status;
        $this->message = $message;
        $this->retryAfterSeconds = $retryAfterSeconds;
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresIn = $expiresIn;
        $this->credentials = $credentials;
        $this->logDetail = $logDetail;
        $this->rawBody = $rawBody;
        $this->shopCredential = $shopCredential;
    }

    public static function success(
        int $status,
        string $message,
        ?string $accessToken = null,
        ?string $refreshToken = null,
        ?int $expiresIn = null,
        ?CredentialSet $credentials = null,
        string $rawBody = '',
        ?ShopCredential $shopCredential = null
    ): self {
        return new self(
            true,
            $status,
            $message,
            null,
            $accessToken,
            $refreshToken,
            $expiresIn,
            $credentials,
            '',
            $rawBody,
            $shopCredential
        );
    }

    public static function fail(int $status, string $message, ?int $retryAfterSeconds = null, string $logDetail = ''): self
    {
        return new self(false, $status, $message, $retryAfterSeconds, null, null, null, null, $logDetail, '', null);
    }
}
