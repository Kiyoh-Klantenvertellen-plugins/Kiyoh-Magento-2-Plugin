<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * HTTP adapter for Kiyoh-Connect public client kiyoh-magento2.
 * Field names come from ConnectFields. This class does not log tokens.
 */
final class ConnectClient
{
    private const TIMEOUT_SECONDS = 30;
    private const MAX_RETRY_AFTER_SECONDS = 3600;

    /**
     * @var ConnectTransportInterface
     */
    private $transport;

    public function __construct(ConnectTransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function registerShop(string $issuer, ShopAddress $shop): ConnectCall
    {
        $response = $this->postJson($issuer, '/shops/register', [
            ConnectFields::CLIENT_ID => ConnectProtocol::CLIENT_ID,
            ConnectFields::SHOP_URL => $shop->shopUrl,
            ConnectFields::REDIRECT_URI => $shop->redirectUri,
        ]);
        if ($response->status === 409) {
            return ConnectCall::fail(
                409,
                'Kiyoh-Connect already registered this shop and did not return a one-time shop credential. Disconnect, then connect again.'
            );
        }
        if (!$response->ok) {
            return $response;
        }

        $decoded = json_decode($response->rawBody, true);
        $credential = is_array($decoded) ? ShopCredential::fromRegistration($decoded, $shop) : null;
        if ($credential === null) {
            return ConnectCall::fail($response->status, 'Kiyoh-Connect did not return a one-time shop credential.');
        }

        return ConnectCall::success(
            $response->status,
            'Shop registered.',
            null,
            null,
            null,
            null,
            '',
            $credential
        );
    }

    public function revokeShop(string $issuer, ShopCredential $credential): ConnectCall
    {
        $response = $this->postJson($issuer, '/shops/revoke', array_merge(
            [ConnectFields::CLIENT_ID => ConnectProtocol::CLIENT_ID],
            $credential->asFormFields()
        ));
        if ($response->ok || $response->status === 404) {
            return ConnectCall::success($response->status === 0 ? 200 : $response->status, 'Shop registration was removed.');
        }

        return $response;
    }

    public function exchangeCode(
        string $issuer,
        ShopCredential $credential,
        string $code,
        string $verifier,
        string $redirectUri
    ): ConnectCall {
        return $this->token($issuer, array_merge([
            ConnectFields::GRANT_TYPE => 'authorization_code',
            ConnectFields::CLIENT_ID => ConnectProtocol::CLIENT_ID,
            ConnectFields::CODE => $code,
            ConnectFields::CODE_VERIFIER => $verifier,
            ConnectFields::REDIRECT_URI => $redirectUri,
        ], $credential->asFormFields()), 'sign-in');
    }

    public function refresh(string $issuer, ShopCredential $credential, string $refreshToken): ConnectCall
    {
        return $this->token($issuer, array_merge([
            ConnectFields::GRANT_TYPE => 'refresh_token',
            ConnectFields::REFRESH_TOKEN => $refreshToken,
            ConnectFields::CLIENT_ID => ConnectProtocol::CLIENT_ID,
        ], $credential->asFormFields()), 'token refresh');
    }

    public function credentials(string $issuer, string $accessToken): ConnectCall
    {
        $response = $this->request(
            'GET',
            $this->url($issuer, '/integration/credentials'),
            [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            null
        );
        if (!$response->ok) {
            return $response;
        }

        $decoded = json_decode($response->rawBody, true);
        if (!is_array($decoded)) {
            return ConnectCall::fail($response->status, 'Kiyoh-Connect returned an unreadable credentials response.');
        }
        $set = CredentialSet::fromApi($decoded);
        if ($set === null) {
            return ConnectCall::fail(
                $response->status,
                'Kiyoh-Connect did not return a location ID and invite API key.'
            );
        }

        return ConnectCall::success($response->status, 'Credentials received.', null, null, null, $set);
    }

    /**
     * OAuth token revocation. Already-revoked tokens count as success.
     */
    public function revokeToken(string $issuer, ShopCredential $credential, string $token): ConnectCall
    {
        $body = http_build_query(array_merge([
            ConnectFields::TOKEN => $token,
            ConnectFields::CLIENT_ID => ConnectProtocol::CLIENT_ID,
        ], $credential->asFormFields()), '', '&', PHP_QUERY_RFC3986);

        $response = $this->request(
            'POST',
            $this->url($issuer, '/revoke'),
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            $body
        );
        if ($response->ok || $response->status === 400) {
            return ConnectCall::success($response->status === 0 ? 200 : $response->status, 'Token revoked.');
        }

        return $response;
    }

    /**
     * @param array<string, string|int> $fields
     */
    private function token(string $issuer, array $fields, string $context): ConnectCall
    {
        $response = $this->request(
            'POST',
            $this->url($issuer, '/token'),
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );
        if (!$response->ok) {
            if ($response->status === 0 || $response->status === 429 || $response->status >= 500) {
                return $response;
            }
            if ($response->status === 401 && $this->errorCode($response->logDetail) === 'invalid_client') {
                return ConnectCall::fail(
                    401,
                    'Kiyoh-Connect rejected the shop credential. Disconnect, then connect again.'
                );
            }

            return ConnectCall::fail(
                $response->status,
                $this->oauthErrorMessage($response->logDetail, $context),
                $response->retryAfterSeconds,
                $response->logDetail
            );
        }

        $decoded = json_decode($response->rawBody, true);
        $access = is_array($decoded) ? ($decoded[ConnectFields::ACCESS_TOKEN] ?? null) : null;
        if (!is_string($access) || $access === '') {
            return ConnectCall::fail($response->status, 'Kiyoh-Connect did not return an access token.');
        }

        $refresh = null;
        $refreshRaw = is_array($decoded) ? ($decoded[ConnectFields::REFRESH_TOKEN] ?? null) : null;
        if (is_string($refreshRaw) && $refreshRaw !== '') {
            $refresh = $refreshRaw;
        }
        $expires = null;
        $expiresRaw = is_array($decoded) ? ($decoded[ConnectFields::EXPIRES_IN] ?? null) : null;
        if (is_numeric($expiresRaw)) {
            $expires = (int) $expiresRaw;
        }

        return ConnectCall::success($response->status, 'Token received.', $access, $refresh, $expires);
    }

    /**
     * @param array<string, string|int> $payload
     */
    private function postJson(string $issuer, string $path, array $payload): ConnectCall
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return ConnectCall::fail(0, 'Could not build the Kiyoh-Connect request.');
        }

        return $this->request(
            'POST',
            $this->url($issuer, $path),
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            $body
        );
    }

    private function errorCode(string $body): ?string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded[ConnectFields::ERROR]) || !is_string($decoded[ConnectFields::ERROR])) {
            return null;
        }

        return $decoded[ConnectFields::ERROR];
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers, ?string $body): ConnectCall
    {
        try {
            $response = $this->transport->send($method, $url, $headers, $body, self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            return ConnectCall::fail(0, 'Could not reach Kiyoh-Connect. Check that this server can open HTTPS connections.', null, $e->getMessage());
        }

        if ($response->status === 429) {
            $retry = $this->retryAfterSeconds($response);
            return ConnectCall::fail(
                429,
                'Kiyoh-Connect is rate limiting requests. Try again in ' . $retry . ' seconds.',
                $retry,
                $response->body
            );
        }
        if ($response->status >= 200 && $response->status < 300) {
            return ConnectCall::success($response->status, 'OK', null, null, null, null, $response->body);
        }

        $fallback = $response->status >= 500
            ? 'Kiyoh-Connect is unavailable (HTTP ' . $response->status . '). Try again.'
            : 'Kiyoh-Connect rejected the request.';
        $message = $this->bodyMessage($response->body, $fallback);

        return ConnectCall::fail($response->status, $message, null, $response->body);
    }

    private function url(string $issuer, string $path): string
    {
        if ($issuer !== ConnectProtocol::ISSUER_KIYOH && $issuer !== ConnectProtocol::ISSUER_KLANTENVERTELLEN) {
            throw new \InvalidArgumentException('Unsupported Kiyoh-Connect issuer');
        }

        return $issuer . $path;
    }

    private function oauthErrorMessage(string $body, string $context): string
    {
        $decoded = json_decode($body, true);
        $fallback = 'Kiyoh-Connect could not complete ' . $context . '. Close the window and try again.';
        if (!is_array($decoded)) {
            return $fallback;
        }
        $description = isset($decoded[ConnectFields::ERROR_DESCRIPTION]) && is_string($decoded[ConnectFields::ERROR_DESCRIPTION])
            ? $decoded[ConnectFields::ERROR_DESCRIPTION]
            : null;

        return PublicText::clean($description, $fallback);
    }

    private function bodyMessage(string $body, string $fallback): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $fallback;
        }
        foreach ([ConnectFields::MESSAGE, ConnectFields::ERROR_DESCRIPTION, ConnectFields::ERROR] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                return PublicText::clean($decoded[$key], $fallback);
            }
        }

        return $fallback;
    }

    private function retryAfterSeconds(ConnectHttpResponse $response): int
    {
        $seconds = null;
        $header = $response->retryAfterHeader;
        if (is_string($header) && $header !== '') {
            if (preg_match('/^\d+$/', $header)) {
                $seconds = (int) $header;
            } else {
                try {
                    $when = new \DateTimeImmutable($header);
                    $seconds = $when->getTimestamp() - time();
                } catch (\Exception $e) {
                    $seconds = null;
                }
            }
        }
        if ($seconds === null) {
            $decoded = json_decode($response->body, true);
            if (is_array($decoded) && isset($decoded[ConnectFields::RETRY_AFTER_SECONDS]) && is_numeric($decoded[ConnectFields::RETRY_AFTER_SECONDS])) {
                $seconds = (int) $decoded[ConnectFields::RETRY_AFTER_SECONDS];
            }
        }
        if ($seconds === null) {
            $seconds = 60;
        }
        if ($seconds < 1) {
            $seconds = 1;
        }
        if ($seconds > self::MAX_RETRY_AFTER_SECONDS) {
            $seconds = self::MAX_RETRY_AFTER_SECONDS;
        }

        return $seconds;
    }
}
