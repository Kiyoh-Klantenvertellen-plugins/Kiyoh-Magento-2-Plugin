<?php

namespace Kiyoh\Reviews\Model\Connect;

final class PendingLogin
{
    /**
     * @var string
     */
    public $state;

    /**
     * @var string
     */
    public $verifier;

    /**
     * @var string
     */
    public $redirectUri;

    /**
     * @var string
     */
    public $origin;

    /**
     * @var string
     */
    public $shopUrl;

    /**
     * @var string
     */
    public $issuer;

    /**
     * @var string
     */
    public $server;

    /**
     * @var string
     */
    public $scopeType;

    /**
     * @var int
     */
    public $scopeId;

    /**
     * @var string
     */
    public $websiteCode;

    /**
     * @var string
     */
    public $storeCode;

    /**
     * @var int
     */
    public $expiresAt;

    public function __construct(
        string $state,
        string $verifier,
        string $redirectUri,
        string $origin,
        string $shopUrl,
        string $issuer,
        string $server,
        string $scopeType,
        int $scopeId,
        string $websiteCode,
        string $storeCode,
        int $expiresAt
    ) {
        $this->state = $state;
        $this->verifier = $verifier;
        $this->redirectUri = $redirectUri;
        $this->origin = $origin;
        $this->shopUrl = $shopUrl;
        $this->issuer = $issuer;
        $this->server = $server;
        $this->scopeType = $scopeType;
        $this->scopeId = $scopeId;
        $this->websiteCode = $websiteCode;
        $this->storeCode = $storeCode;
        $this->expiresAt = $expiresAt;
    }

    public function isUsable(int $now): bool
    {
        return $now < $this->expiresAt;
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'verifier' => $this->verifier,
            'redirectUri' => $this->redirectUri,
            'origin' => $this->origin,
            'shopUrl' => $this->shopUrl,
            'issuer' => $this->issuer,
            'server' => $this->server,
            'scopeType' => $this->scopeType,
            'scopeId' => $this->scopeId,
            'websiteCode' => $this->websiteCode,
            'storeCode' => $this->storeCode,
            'expiresAt' => $this->expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $string = static function (string $key) use ($data): ?string {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                return null;
            }
            return $data[$key];
        };

        $state = $string('state');
        $verifier = $string('verifier');
        $redirectUri = $string('redirectUri');
        $origin = $string('origin');
        $shopUrl = $string('shopUrl');
        $issuer = $string('issuer');
        $server = $string('server');
        $scopeType = $string('scopeType');
        if ($state === null || $verifier === null || $redirectUri === null || $origin === null
            || $shopUrl === null || $issuer === null || $server === null || $scopeType === null
            || !isset($data['scopeId'], $data['expiresAt'])
        ) {
            return null;
        }

        return new self(
            $state,
            $verifier,
            $redirectUri,
            $origin,
            $shopUrl,
            $issuer,
            $server,
            $scopeType,
            (int) $data['scopeId'],
            isset($data['websiteCode']) && is_string($data['websiteCode']) ? $data['websiteCode'] : '',
            isset($data['storeCode']) && is_string($data['storeCode']) ? $data['storeCode'] : '',
            (int) $data['expiresAt']
        );
    }
}
