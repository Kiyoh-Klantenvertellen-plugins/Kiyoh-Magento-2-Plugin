<?php

namespace Kiyoh\Reviews\Test\Unit\Connect;

use Kiyoh\Reviews\Model\Connect\AttemptLedger;
use Kiyoh\Reviews\Model\Connect\AuthorizeUrl;
use Kiyoh\Reviews\Model\Connect\CallbackPage;
use Kiyoh\Reviews\Model\Connect\ConnectClient;
use Kiyoh\Reviews\Model\Connect\ConnectFields;
use Kiyoh\Reviews\Model\Connect\ConnectHttpResponse;
use Kiyoh\Reviews\Model\Connect\ConnectProtocol;
use Kiyoh\Reviews\Model\Connect\CredentialSet;
use Kiyoh\Reviews\Model\Connect\PendingLogin;
use Kiyoh\Reviews\Model\Connect\Pkce;
use Kiyoh\Reviews\Model\Connect\PublicText;
use Kiyoh\Reviews\Model\Connect\ShopAddress;
use Kiyoh\Reviews\Model\Connect\ShopCredential;
use PHPUnit\Framework\TestCase;

class ConnectContractTest extends TestCase
{
    public function testProvisionalFieldNamesStayInOneClass(): void
    {
        $this->assertSame('shop_id', ConnectFields::SHOP_ID);
        $this->assertSame('shop_secret', ConnectFields::SHOP_SECRET);
        $this->assertSame('client_id', ConnectFields::CLIENT_ID);
        $this->assertSame('shop_url', ConnectFields::SHOP_URL);
        $this->assertSame('shop_origin', ConnectFields::SHOP_ORIGIN);
        $this->assertSame('redirect_uri', ConnectFields::REDIRECT_URI);
        $this->assertSame('status', ConnectFields::STATUS);
        $this->assertSame('active', ConnectFields::STATUS_ACTIVE);
        $this->assertSame('pending', ConnectFields::STATUS_PENDING);
        $this->assertSame('code_verifier', ConnectFields::CODE_VERIFIER);
        $this->assertSame('access_token', ConnectFields::ACCESS_TOKEN);
        $this->assertSame('refresh_token', ConnectFields::REFRESH_TOKEN);
        $this->assertSame('inviteApiKey', ConnectFields::INVITE_API_KEY);
    }

    public function testReturnAddressUsesTheShopHostAndDropsTheStorePath(): void
    {
        $shop = ShopAddress::fromBaseUrl('https://Shop.Example/nl/');
        $this->assertNotNull($shop);
        $this->assertSame('https://shop.example', $shop->origin);
        $this->assertSame('https://shop.example/nl/', $shop->shopUrl);
        $this->assertSame('https://shop.example/kiyoh/oauth/callback', $shop->redirectUri);

        $ported = ShopAddress::fromBaseUrl('https://shop.example:8443/index.php');
        $this->assertNotNull($ported);
        $this->assertSame('https://shop.example:8443', $ported->origin);
        $this->assertSame('https://shop.example:8443/kiyoh/oauth/callback', $ported->redirectUri);

        $this->assertNull(ShopAddress::fromBaseUrl('http://shop.example/'));
        $this->assertNull(ShopAddress::fromBaseUrl('https://user:pass@shop.example/'));
    }

    public function testAuthorizeUrlIsPkceAndDoesNotCarrySecrets(): void
    {
        $url = AuthorizeUrl::build(
            ConnectProtocol::ISSUER_KLANTENVERTELLEN,
            'https://shop.example/kiyoh/oauth/callback',
            'state-value',
            'challenge-value'
        );
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertSame('connect.klantenvertellen.nl', parse_url($url, PHP_URL_HOST));
        $this->assertSame('/authorize', parse_url($url, PHP_URL_PATH));
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('kiyoh-magento2', $query['client_id']);
        $this->assertSame('https://shop.example/kiyoh/oauth/callback', $query['redirect_uri']);
        $this->assertSame('integration:connect', $query['scope']);
        $this->assertSame('state-value', $query['state']);
        $this->assertSame('challenge-value', $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertArrayNotHasKey('client_secret', $query);
        $this->assertArrayNotHasKey(ConnectFields::SHOP_SECRET, $query);
        $this->assertArrayNotHasKey('code_verifier', $query);
    }

    public function testPkceChallengeIsS256(): void
    {
        $verifier = 'verifier-value';
        $this->assertSame(
            Pkce::base64Url(hash('sha256', $verifier, true)),
            Pkce::challenge($verifier)
        );
    }

    public function testRegisterSendsNoSharedKeyAndReadsTheShopCredential(): void
    {
        $transport = new FakeConnectTransport([
            new ConnectHttpResponse(200, $this->fixture('register-ok.json')),
        ]);
        $client = new ConnectClient($transport);
        $shop = ShopAddress::fromBaseUrl('https://shop.example/');
        $this->assertNotNull($shop);

        $call = $client->registerShop(ConnectProtocol::ISSUER_KIYOH, $shop);

        $this->assertTrue($call->ok);
        $this->assertNotNull($call->shopCredential);
        $this->assertSame('shop-1', $call->shopCredential->shopId);
        $this->assertSame('sec-1', $call->shopCredential->shopSecret);
        $this->assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://connect.kiyoh.com/shops/register', $request['url']);
        $body = json_decode((string) $request['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('kiyoh-magento2', $body[ConnectFields::CLIENT_ID]);
        $this->assertSame('https://shop.example/', $body[ConnectFields::SHOP_URL]);
        $this->assertSame('https://shop.example/kiyoh/oauth/callback', $body[ConnectFields::REDIRECT_URI]);
        $this->assertCount(3, $body);
        $this->assertArrayNotHasKey('signature', $body);
        $this->assertArrayNotHasKey('issued_at', $body);
        $this->assertArrayNotHasKey('client_secret', $body);
        $this->assertArrayNotHasKey(ConnectFields::SHOP_SECRET, $body);
    }

    public function testRegisterWithoutACredentialFails(): void
    {
        $shop = ShopAddress::fromBaseUrl('https://shop.example/');
        $this->assertNotNull($shop);

        $empty = (new ConnectClient(new FakeConnectTransport([
            new ConnectHttpResponse(200, '{}'),
        ])))->registerShop(ConnectProtocol::ISSUER_KIYOH, $shop);
        $this->assertFalse($empty->ok);
        $this->assertNull($empty->shopCredential);
        $this->assertStringContainsString('one-time shop credential', $empty->message);

        $pendingBody = str_replace('"active"', '"pending"', $this->fixture('register-ok.json'));
        $pending = (new ConnectClient(new FakeConnectTransport([
            new ConnectHttpResponse(200, $pendingBody),
        ])))->registerShop(ConnectProtocol::ISSUER_KIYOH, $shop);
        $this->assertTrue($pending->ok);
        $this->assertNotNull($pending->shopCredential);
        $this->assertSame('shop-1', $pending->shopCredential->shopId);

        $rejected = str_replace('"active"', '"revoked"', $this->fixture('register-ok.json'));
        $badStatus = (new ConnectClient(new FakeConnectTransport([
            new ConnectHttpResponse(200, $rejected),
        ])))->registerShop(ConnectProtocol::ISSUER_KIYOH, $shop);
        $this->assertFalse($badStatus->ok);
        $this->assertNull($badStatus->shopCredential);

        $conflict = (new ConnectClient(new FakeConnectTransport([
            new ConnectHttpResponse(409, '{"error":"exists"}'),
        ])))->registerShop(ConnectProtocol::ISSUER_KIYOH, $shop);
        $this->assertFalse($conflict->ok);
        $this->assertSame(409, $conflict->status);
        $this->assertNull($conflict->shopCredential);
    }

    public function testTokenExchangeUsesPkceRedirectAndTheShopCredential(): void
    {
        $transport = new FakeConnectTransport([
            new ConnectHttpResponse(200, $this->fixture('token-ok.json')),
            new ConnectHttpResponse(200, $this->fixture('credentials-ok.json')),
        ]);
        $client = new ConnectClient($transport);
        $credential = $this->shopCredential();

        $token = $client->exchangeCode(
            ConnectProtocol::ISSUER_KIYOH,
            $credential,
            'auth-code',
            'verifier-value',
            'https://shop.example/kiyoh/oauth/callback'
        );
        $this->assertTrue($token->ok);
        $this->assertSame('access-tok', $token->accessToken);
        $this->assertSame('refresh-tok', $token->refreshToken);
        $this->assertSame(3600, $token->expiresIn);

        parse_str((string) $transport->requests[0]['body'], $form);
        $this->assertSame('authorization_code', $form[ConnectFields::GRANT_TYPE]);
        $this->assertSame('kiyoh-magento2', $form[ConnectFields::CLIENT_ID]);
        $this->assertSame('auth-code', $form[ConnectFields::CODE]);
        $this->assertSame('verifier-value', $form[ConnectFields::CODE_VERIFIER]);
        $this->assertSame('https://shop.example/kiyoh/oauth/callback', $form[ConnectFields::REDIRECT_URI]);
        $this->assertSame('shop-1', $form[ConnectFields::SHOP_ID]);
        $this->assertSame('sec-1', $form[ConnectFields::SHOP_SECRET]);
        $this->assertArrayNotHasKey('client_secret', $form);

        $credentials = $client->credentials(ConnectProtocol::ISSUER_KIYOH, 'access-tok');
        $this->assertTrue($credentials->ok);
        $this->assertNotNull($credentials->credentials);
        $this->assertSame('1001', $credentials->credentials->locationId);
        $this->assertSame(98, $credentials->credentials->tenantId);
        $this->assertSame('Example Shop', $credentials->credentials->locationName);
        $this->assertSame('invite-key', $credentials->credentials->inviteApiKey);
        $this->assertSame('Bearer access-tok', $transport->requests[1]['headers']['Authorization']);
    }

    public function testRateLimitUsesTheRetryAfterHeaderAndDoesNotReturnCredentials(): void
    {
        $transport = new FakeConnectTransport([
            new ConnectHttpResponse(429, $this->fixture('rate-limited.json'), '30'),
        ]);
        $client = new ConnectClient($transport);
        $call = $client->exchangeCode(
            ConnectProtocol::ISSUER_KIYOH,
            $this->shopCredential(),
            'auth-code',
            'verifier-value',
            'https://shop.example/kiyoh/oauth/callback'
        );

        $this->assertFalse($call->ok);
        $this->assertNull($call->accessToken);
        $this->assertNull($call->credentials);
        $this->assertSame(30, $call->retryAfterSeconds);
        $this->assertStringContainsString('30 seconds', $call->message);
        $this->assertCount(1, $transport->requests);
    }

    public function testRateLimitFallsBackToTheFixtureBody(): void
    {
        $transport = new FakeConnectTransport([
            new ConnectHttpResponse(429, $this->fixture('rate-limited.json')),
        ]);
        $call = (new ConnectClient($transport))->refresh(
            ConnectProtocol::ISSUER_KLANTENVERTELLEN,
            $this->shopCredential(),
            'refresh-tok'
        );

        $this->assertSame(45, $call->retryAfterSeconds);
        $this->assertStringContainsString('https://connect.klantenvertellen.nl/token', $transport->requests[0]['url']);
        parse_str((string) $transport->requests[0]['body'], $form);
        $this->assertSame('refresh_token', $form[ConnectFields::GRANT_TYPE]);
        $this->assertSame('refresh-tok', $form[ConnectFields::REFRESH_TOKEN]);
        $this->assertSame('sec-1', $form[ConnectFields::SHOP_SECRET]);
        $this->assertArrayNotHasKey('client_secret', $form);
    }

    public function testInvalidGrantBecomesAnAdminMessageWithoutTheCode(): void
    {
        $transport = new FakeConnectTransport([
            new ConnectHttpResponse(400, $this->fixture('token-invalid.json')),
        ]);
        $call = (new ConnectClient($transport))->exchangeCode(
            ConnectProtocol::ISSUER_KIYOH,
            $this->shopCredential(),
            'auth-code',
            'verifier-value',
            'https://shop.example/kiyoh/oauth/callback'
        );

        $this->assertFalse($call->ok);
        $this->assertSame('The authorization code is invalid or was already used.', $call->message);
        $this->assertStringNotContainsString('auth-code', $call->message);
    }

    public function testRevokeSendsTheShopCredentialAndTreatsAlreadyGoneAsSuccess(): void
    {
        $transport = new FakeConnectTransport([
            new ConnectHttpResponse(404, ''),
            new ConnectHttpResponse(400, '{"error":"invalid_token"}'),
        ]);
        $client = new ConnectClient($transport);
        $shop = ShopAddress::fromBaseUrl('https://shop.example/');
        $this->assertNotNull($shop);
        $credential = $this->shopCredential();

        $shopCall = $client->revokeShop(ConnectProtocol::ISSUER_KIYOH, $credential);
        $this->assertTrue($shopCall->ok);
        $body = json_decode((string) $transport->requests[0]['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('kiyoh-magento2', $body[ConnectFields::CLIENT_ID]);
        $this->assertSame('shop-1', $body[ConnectFields::SHOP_ID]);
        $this->assertSame('sec-1', $body[ConnectFields::SHOP_SECRET]);
        $this->assertCount(3, $body);
        $this->assertArrayNotHasKey(ConnectFields::SHOP_URL, $body);
        $this->assertArrayNotHasKey(ConnectFields::REDIRECT_URI, $body);
        $this->assertArrayNotHasKey('signature', $body);
        $this->assertArrayNotHasKey('client_secret', $body);

        $tokenCall = $client->revokeToken(ConnectProtocol::ISSUER_KIYOH, $credential, 'refresh-tok');
        $this->assertTrue($tokenCall->ok);
        parse_str((string) $transport->requests[1]['body'], $form);
        $this->assertSame('refresh-tok', $form[ConnectFields::TOKEN]);
        $this->assertSame('shop-1', $form[ConnectFields::SHOP_ID]);
        $this->assertSame('sec-1', $form[ConnectFields::SHOP_SECRET]);
        $this->assertCount(4, $form);
        $this->assertArrayNotHasKey('token_type_hint', $form);
        $this->assertArrayNotHasKey('client_secret', $form);
    }

    public function testInvalidClientDoesNotEchoTheShopCredential(): void
    {
        $transport = new FakeConnectTransport([
            new ConnectHttpResponse(401, '{"error":"invalid_client"}'),
        ]);
        $call = (new ConnectClient($transport))->exchangeCode(
            ConnectProtocol::ISSUER_KIYOH,
            $this->shopCredential(),
            'auth-code',
            'verifier-value',
            'https://shop.example/kiyoh/oauth/callback'
        );

        $this->assertFalse($call->ok);
        $this->assertSame(401, $call->status);
        $this->assertSame('Kiyoh-Connect rejected the shop credential. Disconnect, then connect again.', $call->message);
        $this->assertStringNotContainsString('sec-1', $call->message);
    }

    public function testPendingLoginIsSingleUse(): void
    {
        $ledger = new AttemptLedger();
        $login = new PendingLogin(
            'state-value-1234567890',
            'verifier-value',
            'https://shop.example/kiyoh/oauth/callback',
            'https://shop.example',
            'https://shop.example/',
            ConnectProtocol::ISSUER_KIYOH,
            'kiyoh.com',
            'stores',
            1,
            '',
            'default',
            1700000900
        );
        $ledger->put($login);
        $this->assertNotNull($ledger->peek($login->state, 1700000000));
        $this->assertNotNull($ledger->peek($login->state, 1700000000));
        $this->assertNotNull($ledger->consume($login->state, 1700000000));
        $this->assertNull($ledger->peek($login->state, 1700000000));
        $this->assertNull($ledger->consume($login->state, 1700000000));

        $ledger->put($login);
        $this->assertNull($ledger->consume($login->state, 1700000900));
    }

    public function testCredentialNormalizerRejectsHtmlAndRequiresTheContractFields(): void
    {
        $set = CredentialSet::fromApi([
            ConnectFields::LOCATION_ID => '1001',
            ConnectFields::TENANT_ID => '99',
            ConnectFields::LOCATION_NAME => '<b>Example</b>',
            ConnectFields::INVITE_API_KEY => 'invite-key',
        ]);
        $this->assertNotNull($set);
        $this->assertSame('Example', $set->locationName);
        $this->assertSame(99, $set->tenantId);
        $this->assertNull(CredentialSet::fromApi(['locationId' => '1001', 'tenantId' => 98]));

        $this->assertNotNull(ShopCredential::fromApi([
            ConnectFields::SHOP_ID => 'shop-1',
            ConnectFields::SHOP_SECRET => 'sec-1',
        ]));
        $this->assertNull(ShopCredential::fromApi([ConnectFields::SHOP_ID => 'shop-1']));
        $this->assertNull(ShopCredential::fromApi([
            ConnectFields::SHOP_ID => 'shop-1',
            ConnectFields::SHOP_SECRET => "sec\n1",
        ]));
    }

    public function testCallbackPageEscapesTheMessage(): void
    {
        $html = CallbackPage::render(false, '<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPublicTextDropsSecrets(): void
    {
        $this->assertSame(
            'fallback',
            PublicText::clean('token bearer abcdefghijklmnopqrstuvwxyz', 'fallback')
        );
        $this->assertSame('Try again.', PublicText::clean('Try again.', 'fallback'));
    }

    private function shopCredential(): ShopCredential
    {
        $credential = ShopCredential::fromApi([
            ConnectFields::SHOP_ID => 'shop-1',
            ConnectFields::SHOP_SECRET => 'sec-1',
        ]);
        $this->assertInstanceOf(ShopCredential::class, $credential);

        return $credential;
    }

    private function fixture(string $name): string
    {
        $path = dirname(__DIR__, 3) . '/tests/fixtures/kiyoh-connect/' . $name;
        $json = file_get_contents($path);
        $this->assertIsString($json);

        return $json;
    }
}
