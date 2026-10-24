<?php

namespace Kiyoh\Reviews\Model\Connect;

final class AuthorizeUrl
{
    public static function build(string $issuer, string $redirectUri, string $state, string $challenge): string
    {
        if ($issuer !== ConnectProtocol::ISSUER_KIYOH && $issuer !== ConnectProtocol::ISSUER_KLANTENVERTELLEN) {
            throw new \InvalidArgumentException('Unsupported Kiyoh-Connect issuer');
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => ConnectProtocol::CLIENT_ID,
            'redirect_uri' => $redirectUri,
            'scope' => ConnectProtocol::SCOPE,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => ConnectProtocol::CODE_CHALLENGE_METHOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return $issuer . '/authorize?' . $query;
    }
}
