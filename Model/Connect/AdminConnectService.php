<?php

namespace Kiyoh\Reviews\Model\Connect;

use Kiyoh\Reviews\Api\ApiServiceInterface;
use Kiyoh\Reviews\Model\Health\HealthReporter;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Admin and storefront orchestration for Kiyoh-Connect sign-in.
 * Unexpected failures become a short message. Tokens stay out of that message.
 */
class AdminConnectService
{
    /**
     * @var ConnectClient
     */
    private $client;

    /**
     * @var PendingLoginStore
     */
    private $logins;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ReinitableConfigInterface
     */
    private $reinitableConfig;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    /**
     * @var HealthReporter
     */
    private $healthReporter;

    /**
     * @var ApiServiceInterface
     */
    private $apiService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ConnectClient $client,
        PendingLoginStore $logins,
        EncryptorInterface $encryptor,
        WriterInterface $configWriter,
        ResourceConnection $resource,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        ReinitableConfigInterface $reinitableConfig,
        TypeListInterface $cacheTypeList,
        HealthReporter $healthReporter,
        ApiServiceInterface $apiService,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->logins = $logins;
        $this->encryptor = $encryptor;
        $this->configWriter = $configWriter;
        $this->resource = $resource;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->reinitableConfig = $reinitableConfig;
        $this->cacheTypeList = $cacheTypeList;
        $this->healthReporter = $healthReporter;
        $this->apiService = $apiService;
        $this->logger = $logger;
    }

    /**
     * @return array{success:bool,message:string,authorizeUrl?:string,attempt?:string}
     */
    public function begin(string $server, string $storeParam, string $websiteParam): array
    {
        try {
            return $this->beginInner($server, $storeParam, $websiteParam);
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: start failed', ['exception' => $e->getMessage()]);
            return $this->fail('Could not start Kiyoh-Connect. Check the Magento log for the reason.');
        }
    }

    /**
     * @return array{done:bool,ok?:bool,message?:string}
     */
    public function status(string $attempt, string $storeParam, string $websiteParam): array
    {
        try {
            $target = $this->target($storeParam, $websiteParam);
            if (isset($target['error'])) {
                return ['done' => false];
            }
            $stored = $this->readExact($target, ConnectConfig::LAST_ATTEMPT);
            if (!is_string($stored) || strlen($stored) !== strlen($attempt) || !hash_equals($stored, $attempt)) {
                return ['done' => false];
            }
            $ok = $this->readExact($target, ConnectConfig::LAST_OK) === '1';
            $message = $this->readExact($target, ConnectConfig::LAST_MESSAGE)
                ?? ($ok ? 'Connected.' : 'Kiyoh-Connect sign-in did not finish.');

            return ['done' => true, 'ok' => $ok, 'message' => $message];
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: status failed', ['exception' => $e->getMessage()]);
            return ['done' => false];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function completeCallback(
        string $code,
        string $state,
        string $error,
        string $requestHost = '',
        bool $browserReturn = true
    ): array {
        try {
            return $this->completeInner($code, $state, $error, $requestHost, $browserReturn);
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: callback failed', ['exception' => $e->getMessage()]);
            return [
                'ok' => false,
                'message' => 'Kiyoh-Connect sign-in could not be finished. Close this window and try again from the Magento admin.',
            ];
        }
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function disconnect(string $storeParam, string $websiteParam): array
    {
        try {
            return $this->disconnectInner($storeParam, $websiteParam);
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: disconnect failed', ['exception' => $e->getMessage()]);
            return $this->fail('Could not disconnect. Check the Magento log for the reason.');
        }
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function test(string $storeParam, string $websiteParam): array
    {
        try {
            return $this->testInner($storeParam, $websiteParam);
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: test failed', ['exception' => $e->getMessage()]);
            return $this->fail('Connection test could not be completed. Check the Magento log for the reason.');
        }
    }

    /**
     * Best-effort remote revoke for every scope that stored a shop credential.
     */
    public function revokeAllForUninstall(): void
    {
        foreach ($this->credentialScopes() as $target) {
            $credential = $this->readShopCredential($target);
            if ($credential === null) {
                $this->logger->info('Kiyoh Connect: uninstall found no shop credential for this scope', [
                    'scope' => $target['type'],
                    'scope_id' => $target['id'],
                ]);
                continue;
            }
            $refresh = $this->readSecret(ConnectConfig::REFRESH, $target);
            $server = $this->read(ConnectConfig::SERVER, $target) ?? 'kiyoh.com';
            $this->revokeRemote($target, $server, $refresh, $credential);
        }
    }

    /**
     * @return array{success:bool,message:string,authorizeUrl?:string,attempt?:string}
     */
    private function beginInner(string $server, string $storeParam, string $websiteParam): array
    {
        $target = $this->target($storeParam, $websiteParam);
        if (isset($target['error'])) {
            return $this->fail($target['error']);
        }
        if ($server === '') {
            $server = (string) ($this->read(ConnectConfig::SERVER, $target) ?? '');
        }
        $issuer = ConnectProtocol::issuerForServer($server);
        if ($issuer === null) {
            return $this->fail('Choose Server kiyoh.com or klantenvertellen.nl, save if needed, and try again.');
        }

        $shop = ShopAddress::fromBaseUrl($target['baseUrl']);
        if ($shop === null) {
            return $this->fail('This scope needs an HTTPS secure base URL with no username or password in it before you can connect. The return address is https://{shop-host}/kiyoh/oauth/callback.');
        }

        $now = time();
        $this->logins->deleteExpired($now);
        $prepared = $this->prepareShopCredential($target, $issuer, $shop);
        if ($prepared !== null) {
            return $this->fail($prepared);
        }

        $verifier = Pkce::verifier();
        $state = Pkce::state();
        $login = new PendingLogin(
            $state,
            $verifier,
            $shop->redirectUri,
            $shop->origin,
            $shop->shopUrl,
            $issuer,
            $server,
            $target['type'],
            $target['id'],
            $target['websiteCode'],
            $target['storeCode'],
            $now + ConnectProtocol::LOGIN_TTL_SECONDS
        );
        $this->logins->put($login);

        return [
            'success' => true,
            'message' => 'Continue in the Kiyoh-Connect window.',
            'authorizeUrl' => AuthorizeUrl::build($issuer, $shop->redirectUri, $state, Pkce::challenge($verifier)),
            'attempt' => $state,
        ];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function completeInner(
        string $code,
        string $state,
        string $error,
        string $requestHost,
        bool $browserReturn
    ): array {
        if ($error !== '') {
            $message = $error === 'access_denied'
                ? 'Sign-in was cancelled.'
                : PublicText::clean($error, 'Kiyoh-Connect did not complete sign-in.');
            $this->failAttempt($state, $message);
            return ['ok' => false, 'message' => $message];
        }
        if (!$this->tokenShape($state) || !$this->tokenShape($code)) {
            return [
                'ok' => false,
                'message' => 'This sign-in link is invalid or was already used. Click Connect again.',
            ];
        }

        $login = $this->logins->peek($state, time());
        if ($login === null) {
            return [
                'ok' => false,
                'message' => 'This sign-in link is invalid or was already used. Click Connect again.',
            ];
        }
        if (!CallbackGuard::hostMatches($login->redirectUri, $requestHost)) {
            $this->logins->consume($state, time());
            $host = parse_url($login->redirectUri, PHP_URL_HOST);
            $message = 'This sign-in returned to the wrong shop. Connect again so it can return to '
                . (is_string($host) && $host !== '' ? $host : 'this store view') . '.';
            $this->record($login, false, $message);
            $this->flushConfig();
            return ['ok' => false, 'message' => $message];
        }
        if (!$browserReturn) {
            return [
                'ok' => false,
                'message' => 'Kiyoh-Connect did not return the browser to this shop. Close this window and click Connect again.',
            ];
        }
        $login = $this->logins->consume($state, time());
        if ($login === null) {
            return [
                'ok' => false,
                'message' => 'This sign-in link is invalid or was already used. Click Connect again.',
            ];
        }

        $scope = ['type' => $login->scopeType, 'id' => $login->scopeId];
        $credential = $this->usableShopCredential($scope, $login->issuer, $login->redirectUri);
        if ($credential === null) {
            $message = 'The one-time shop credential for this scope is missing. Disconnect, then click Connect again.';
            $this->record($login, false, $message);
            return ['ok' => false, 'message' => $message];
        }

        $token = $this->client->exchangeCode($login->issuer, $credential, $code, $login->verifier, $login->redirectUri);
        if (!$token->ok || $token->accessToken === null) {
            $this->logCall('token', $token);
            $this->record($login, false, $token->message);
            return ['ok' => false, 'message' => $token->message];
        }

        $credentials = $this->client->credentials($login->issuer, $token->accessToken);
        if (!$credentials->ok || $credentials->credentials === null) {
            $this->logCall('credentials', $credentials);
            $this->record($login, false, $credentials->message);
            return ['ok' => false, 'message' => $credentials->message];
        }

        $expected = ConnectProtocol::expectedTenant($login->server);
        if ($expected === null || $credentials->credentials->tenantId !== $expected) {
            $message = 'This account belongs to tenant ' . $credentials->credentials->tenantId
                . ', but ' . $login->server . ' expects tenant ' . (string) $expected
                . '. Change the Server field and connect again.';
            $this->record($login, false, $message);
            return ['ok' => false, 'message' => $message];
        }

        $refresh = $token->refreshToken;
        $this->persist($login, $credentials->credentials, $refresh, $token->accessToken, $token->expiresIn);
        $name = $credentials->credentials->locationName !== ''
            ? $credentials->credentials->locationName
            : 'location ' . $credentials->credentials->locationId;
        $message = 'Connected to ' . $name . ' (location ' . $credentials->credentials->locationId . ') on ' . $login->server . '.';
        $this->record($login, true, $message);
        $this->flushConfig();
        try {
            $this->healthReporter->noteConfigSaved($login->websiteCode, $login->storeCode);
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: health report after sign-in failed', ['exception' => $e->getMessage()]);
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function disconnectInner(string $storeParam, string $websiteParam): array
    {
        $target = $this->target($storeParam, $websiteParam);
        if (isset($target['error'])) {
            return $this->fail($target['error']);
        }

        $source = $this->read(ConnectConfig::SOURCE, $target);
        $refresh = $this->readSecret(ConnectConfig::REFRESH, $target);
        $credential = $this->readShopCredential($target);
        if ($source !== 'connect' && $refresh === null && $credential === null) {
            return [
                'success' => true,
                'message' => 'No Kiyoh-Connect sign-in is stored for this scope. A manually entered API token, if any, was left in place.',
            ];
        }

        $server = $this->read(ConnectConfig::SERVER, $target) ?? 'kiyoh.com';
        $remoteNote = '';
        $dropShopCredential = false;
        if ($credential !== null) {
            $shared = $this->shopCredentialIsShared($target, $credential);
            if ($shared) {
                $this->revokeTokenOnly($this->issuerForRevoke($target, $server), $refresh, $credential);
                $dropShopCredential = true;
                $remoteNote = ' Other store views on this return address still use the shop registration, so it was left in place.';
            } else {
                $revoked = $this->revokeRemote($target, $server, $refresh, $credential);
                if ($revoked) {
                    $dropShopCredential = true;
                } else {
                    $remoteNote = ' Kiyoh-Connect could not revoke the shop. Local sign-in data was cleared, and the one-time shop credential was kept so Disconnect can be tried again.';
                }
            }
        } else {
            $remoteNote = ' No shop credential is stored, so the remote registration was not revoked. Local sign-in data was cleared.';
        }

        $this->configWriter->delete(ConnectConfig::REFRESH, $target['type'], $target['id']);
        $this->configWriter->delete(ConnectConfig::ACCESS, $target['type'], $target['id']);
        $this->configWriter->delete(ConnectConfig::ACCESS_EXPIRES, $target['type'], $target['id']);
        $this->configWriter->delete(ConnectConfig::LAST_ATTEMPT, $target['type'], $target['id']);
        $this->configWriter->delete(ConnectConfig::LAST_OK, $target['type'], $target['id']);
        $this->configWriter->delete(ConnectConfig::LAST_MESSAGE, $target['type'], $target['id']);
        $this->configWriter->delete(ConnectConfig::LAST_AT, $target['type'], $target['id']);
        $this->configWriter->delete(ConnectConfig::LOCATION_NAME, $target['type'], $target['id']);
        if ($dropShopCredential) {
            $this->deleteShopCredential($target);
        }

        if ($source === 'connect') {
            $this->configWriter->delete(ConnectConfig::LOCATION_ID, $target['type'], $target['id']);
            $this->configWriter->delete(ConnectConfig::API_TOKEN, $target['type'], $target['id']);
            $this->configWriter->delete(ConnectConfig::SOURCE, $target['type'], $target['id']);
            $this->flushConfig();
            return [
                'success' => true,
                'message' => 'Disconnected from Kiyoh-Connect. The Location ID and API token for this scope were cleared.' . $remoteNote,
            ];
        }

        $this->flushConfig();
        $kept = $source === 'manual'
            ? 'The manually entered API token was left in place.'
            : 'No Location ID was cleared.';
        return [
            'success' => true,
            'message' => 'Kiyoh-Connect sign-in was removed. ' . $kept . $remoteNote,
        ];
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function testInner(string $storeParam, string $websiteParam): array
    {
        $target = $this->target($storeParam, $websiteParam);
        if (isset($target['error'])) {
            return $this->fail($target['error']);
        }

        $server = $this->read(ConnectConfig::SERVER, $target) ?? '';
        $locationId = $this->read(ConnectConfig::LOCATION_ID, $target);
        $token = $this->readSecret(ConnectConfig::API_TOKEN, $target);
        if ($server === '' || $locationId === null || $token === null) {
            return $this->fail('Enter a Location ID and API token, or use Connect with Kiyoh / Klantenvertellen.');
        }

        $result = $this->apiService->validateNewApiCredentials($server, $token, $locationId);
        if (!empty($result['success'])) {
            $name = $this->read(ConnectConfig::LOCATION_NAME, $target);
            $who = $name !== null && $name !== '' ? $name : 'location ' . $locationId;
            return ['success' => true, 'message' => 'Connection succeeded for ' . $who . ' on ' . $server . '.'];
        }

        $reason = PublicText::clean(
            isset($result['message']) && is_string($result['message']) ? $result['message'] : null,
            'Connection failed. Check the Location ID and API token.'
        );
        $refreshed = $this->refreshStoredCredentials($target, $server);
        if ($refreshed === null) {
            return $this->fail('Connection failed: ' . $reason);
        }
        if ($refreshed !== true) {
            return $this->fail(is_string($refreshed) ? $refreshed : 'Connection failed: ' . $reason);
        }

        $token = $this->readSecret(ConnectConfig::API_TOKEN, $target);
        $locationId = $this->read(ConnectConfig::LOCATION_ID, $target);
        if ($token === null || $locationId === null) {
            return $this->fail('Connection failed: ' . $reason);
        }
        $again = $this->apiService->validateNewApiCredentials($server, $token, $locationId);
        if (!empty($again['success'])) {
            return ['success' => true, 'message' => 'Connection succeeded for location ' . $locationId . ' on ' . $server . ' after refreshing Kiyoh-Connect.'];
        }

        return $this->fail('Connection failed: ' . $reason);
    }

    /**
     * @param array{type:string,id:int,websiteCode:string,storeCode:string,baseUrl:string,label:string} $target
     * @return true|string|null null when there is nothing to refresh
     */
    private function refreshStoredCredentials(array $target, string $server)
    {
        $refresh = $this->readSecret(ConnectConfig::REFRESH, $target);
        if ($refresh === null) {
            return null;
        }
        $issuer = ConnectProtocol::issuerForServer($server);
        if ($issuer === null) {
            return 'Connection failed. The saved Server value is not kiyoh.com or klantenvertellen.nl.';
        }
        $credential = $this->readShopCredential($target);
        if ($credential === null) {
            return null;
        }

        $token = $this->client->refresh($issuer, $credential, $refresh);
        if (!$token->ok || $token->accessToken === null) {
            $this->logCall('refresh', $token);
            return 'Connection failed. The saved Kiyoh-Connect session could not be refreshed. Connect again.';
        }
        $credentials = $this->client->credentials($issuer, $token->accessToken);
        if (!$credentials->ok || $credentials->credentials === null) {
            $this->logCall('credentials', $credentials);
            return 'Connection failed. Kiyoh-Connect did not return a new invite API key. Connect again.';
        }
        $expected = ConnectProtocol::expectedTenant($server);
        if ($expected === null || $credentials->credentials->tenantId !== $expected) {
            return 'Connection failed. The refreshed account is for a different Kiyoh-Connect tenant than the selected Server.';
        }

        $login = new PendingLogin(
            'refresh',
            'refresh',
            '',
            '',
            '',
            $issuer,
            $server,
            $target['type'],
            $target['id'],
            $target['websiteCode'],
            $target['storeCode'],
            time()
        );
        $this->persist($login, $credentials->credentials, $token->refreshToken ?? $refresh, $token->accessToken, $token->expiresIn);
        $this->flushConfig();

        return true;
    }

    private function persist(
        PendingLogin $login,
        CredentialSet $credentials,
        ?string $refreshToken,
        string $accessToken,
        ?int $expiresIn
    ): void {
        $scope = $login->scopeType;
        $scopeId = $login->scopeId;
        $this->configWriter->save(ConnectConfig::ENABLED, '1', $scope, $scopeId);
        $this->configWriter->save(ConnectConfig::SERVER, $login->server, $scope, $scopeId);
        $this->configWriter->save(ConnectConfig::LOCATION_ID, $credentials->locationId, $scope, $scopeId);
        $this->configWriter->save(ConnectConfig::API_TOKEN, $this->encryptor->encrypt($credentials->inviteApiKey), $scope, $scopeId);
        $this->configWriter->save(ConnectConfig::SOURCE, 'connect', $scope, $scopeId);
        $this->configWriter->save(ConnectConfig::LOCATION_NAME, $credentials->locationName, $scope, $scopeId);
        if ($refreshToken !== null && $refreshToken !== '') {
            $this->configWriter->save(ConnectConfig::REFRESH, $this->encryptor->encrypt($refreshToken), $scope, $scopeId);
        }
        $this->configWriter->save(ConnectConfig::ACCESS, $this->encryptor->encrypt($accessToken), $scope, $scopeId);
        $ttl = $expiresIn !== null && $expiresIn > 0 ? $expiresIn : 3600;
        $this->configWriter->save(ConnectConfig::ACCESS_EXPIRES, (string) (time() + $ttl), $scope, $scopeId);
    }

    private function failAttempt(string $state, string $message): void
    {
        if (!$this->tokenShape($state)) {
            return;
        }
        $login = $this->logins->consume($state, time());
        if ($login === null) {
            return;
        }
        $this->record($login, false, $message);
        $this->flushConfig();
    }

    private function record(PendingLogin $login, bool $ok, string $message): void
    {
        $this->configWriter->save(ConnectConfig::LAST_ATTEMPT, $login->state, $login->scopeType, $login->scopeId);
        $this->configWriter->save(ConnectConfig::LAST_OK, $ok ? '1' : '0', $login->scopeType, $login->scopeId);
        $this->configWriter->save(ConnectConfig::LAST_MESSAGE, $message, $login->scopeType, $login->scopeId);
        $this->configWriter->save(ConnectConfig::LAST_AT, (string) time(), $login->scopeType, $login->scopeId);
        $this->flushConfig();
    }

    /**
     * Register once per scope, issuer, and return address. A credential stored
     * on another scope is not reused. The credential is written on the current
     * scope before the popup opens.
     *
     * @param array{type:string,id:int} $target
     * @return string|null Admin error, or null when a credential is stored.
     */
    private function prepareShopCredential(array $target, string $issuer, ShopAddress $shop): ?string
    {
        $local = $this->usableShopCredential($target, $issuer, $shop->redirectUri);
        if ($local !== null) {
            return null;
        }

        $registered = $this->client->registerShop($issuer, $shop);
        if (!$registered->ok || $registered->shopCredential === null) {
            $this->logCall('register', $registered);
            if ($this->readShopCredential($target) !== null) {
                return 'The return address or server changed, and Kiyoh-Connect did not issue a new one-time shop credential. Disconnect, then connect again.';
            }

            return $registered->message;
        }

        $this->saveShopCredential($target, $registered->shopCredential, $issuer, $shop->redirectUri);
        $this->flushConfig();

        return null;
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function usableShopCredential(array $target, string $issuer, string $redirectUri): ?ShopCredential
    {
        $stored = $this->readShopCredential($target);
        if ($stored === null) {
            return null;
        }
        if ($this->readExact($target, ConnectConfig::SHOP_ISSUER) !== $issuer) {
            return null;
        }
        if ($this->readExact($target, ConnectConfig::SHOP_REDIRECT) !== $redirectUri) {
            return null;
        }

        return $stored;
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function saveShopCredential(array $target, ShopCredential $credential, string $issuer, string $redirectUri): void
    {
        $scope = $target['type'];
        $scopeId = $target['id'];
        $this->configWriter->save(ConnectConfig::SHOP_ID, $this->encryptor->encrypt($credential->shopId), $scope, $scopeId);
        $this->configWriter->save(ConnectConfig::SHOP_SECRET, $this->encryptor->encrypt($credential->shopSecret), $scope, $scopeId);
        $this->configWriter->save(ConnectConfig::SHOP_ISSUER, $issuer, $scope, $scopeId);
        $this->configWriter->save(ConnectConfig::SHOP_REDIRECT, $redirectUri, $scope, $scopeId);
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function deleteShopCredential(array $target): void
    {
        foreach ([ConnectConfig::SHOP_ID, ConnectConfig::SHOP_SECRET, ConnectConfig::SHOP_ISSUER, ConnectConfig::SHOP_REDIRECT] as $path) {
            $this->configWriter->delete($path, $target['type'], $target['id']);
        }
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function readShopCredential(array $target): ?ShopCredential
    {
        $shopId = $this->decryptExact($target, ConnectConfig::SHOP_ID);
        $shopSecret = $this->decryptExact($target, ConnectConfig::SHOP_SECRET);
        if ($shopId === null || $shopSecret === null) {
            return null;
        }

        return new ShopCredential($shopId, $shopSecret);
    }

    /**
     * Shop credentials are read from the scope row itself. Inherited config
     * would treat a parent registration as if this scope owned it.
     *
     * @param array{type:string,id:int} $target
     */
    private function readExact(array $target, string $path): ?string
    {
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['value'])
                ->where('path = ?', $path)
                ->where('scope = ?', $target['type'])
                ->where('scope_id = ?', $target['id'])
                ->limit(1);
            $value = $connection->fetchOne($select);
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: could not read stored shop credential', ['path' => $path]);
            return null;
        }
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function decryptExact(array $target, string $path): ?string
    {
        $stored = $this->readExact($target, $path);
        if ($stored === null) {
            return null;
        }
        try {
            $plain = trim($this->encryptor->decrypt($stored));
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: stored shop credential could not be decrypted', ['path' => $path]);
            return null;
        }

        return $plain === '' ? null : $plain;
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function shopCredentialIsShared(array $target, ShopCredential $credential): bool
    {
        foreach ($this->credentialScopes() as $scope) {
            if ($scope['type'] === $target['type'] && $scope['id'] === $target['id']) {
                continue;
            }
            $other = $this->readShopCredential($scope);
            if ($other === null || strlen($other->shopId) !== strlen($credential->shopId)) {
                continue;
            }
            if (hash_equals($other->shopId, $credential->shopId)) {
                return true;
            }
        }

        return false;
    }

    private function revokeTokenOnly(?string $issuer, ?string $refreshToken, ShopCredential $credential): void
    {
        if ($refreshToken === null || $issuer === null) {
            return;
        }
        $revoked = $this->client->revokeToken($issuer, $credential, $refreshToken);
        if (!$revoked->ok) {
            $this->logCall('revoke', $revoked);
        }
    }

    /**
     * True when the shop registration itself was revoked or was already gone.
     *
     * @param array{type:string,id:int,baseUrl:string} $target
     */
    private function revokeRemote(array $target, string $server, ?string $refreshToken, ShopCredential $credential): bool
    {
        $issuer = $this->issuerForRevoke($target, $server);
        if ($issuer === null) {
            return false;
        }

        $this->revokeTokenOnly($issuer, $refreshToken, $credential);
        $shopRevoke = $this->client->revokeShop($issuer, $credential);
        if (!$shopRevoke->ok) {
            $this->logCall('shops/revoke', $shopRevoke);
            return false;
        }

        return true;
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function issuerForRevoke(array $target, string $server): ?string
    {
        $stored = $this->readExact($target, ConnectConfig::SHOP_ISSUER);
        if ($stored === ConnectProtocol::ISSUER_KIYOH || $stored === ConnectProtocol::ISSUER_KLANTENVERTELLEN) {
            return $stored;
        }

        return ConnectProtocol::issuerForServer($server);
    }

    /**
     * @return array<int, array{type:string,id:int,baseUrl:string}>
     */
    private function credentialScopes(): array
    {
        $scopes = [];
        try {
            $default = $this->storeManager->getDefaultStoreView();
            if (!$default) {
                $default = $this->storeManager->getStore();
            }
            $scopes[] = [
                'type' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                'id' => 0,
                'baseUrl' => (string) $default->getBaseUrl(UrlInterface::URL_TYPE_LINK, true),
            ];
            foreach ($this->storeManager->getWebsites() as $website) {
                $store = $website->getDefaultStore();
                if (!$store) {
                    $stores = $website->getStores();
                    $store = $stores ? reset($stores) : null;
                }
                if (!$store) {
                    continue;
                }
                $scopes[] = [
                    'type' => 'websites',
                    'id' => (int) $website->getId(),
                    'baseUrl' => (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true),
                ];
            }
            foreach ($this->storeManager->getStores() as $store) {
                $scopes[] = [
                    'type' => 'stores',
                    'id' => (int) $store->getId(),
                    'baseUrl' => (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: could not list scopes', ['exception' => $e->getMessage()]);
        }

        return $scopes;
    }

    /**
     * @return array{type:string,id:int,websiteCode:string,storeCode:string,baseUrl:string,label:string,error?:string}
     */
    private function target(string $storeParam, string $websiteParam): array
    {
        try {
            if ($storeParam !== '' && $storeParam !== '0') {
                $store = $this->storeManager->getStore((int) $storeParam);
                return [
                    'type' => 'stores',
                    'id' => (int) $store->getId(),
                    'websiteCode' => '',
                    'storeCode' => (string) $store->getCode(),
                    'baseUrl' => $this->baseUrlFor('stores', (int) $store->getId(), (int) $store->getWebsiteId(), $store),
                    'label' => (string) $store->getName(),
                ];
            }
            if ($websiteParam !== '') {
                $website = $this->storeManager->getWebsite((int) $websiteParam);
                $store = $website->getDefaultStore();
                if (!$store) {
                    $stores = $website->getStores();
                    $store = $stores ? reset($stores) : null;
                }
                if (!$store) {
                    return ['error' => 'That website has no store view to connect.', 'type' => 'websites', 'id' => 0, 'websiteCode' => '', 'storeCode' => '', 'baseUrl' => '', 'label' => ''];
                }
                return [
                    'type' => 'websites',
                    'id' => (int) $website->getId(),
                    'websiteCode' => (string) $website->getCode(),
                    'storeCode' => '',
                    'baseUrl' => $this->baseUrlFor('websites', (int) $website->getId(), null, $store),
                    'label' => (string) $website->getName(),
                ];
            }

            $store = $this->storeManager->getDefaultStoreView();
            if (!$store) {
                $store = $this->storeManager->getStore();
            }
            return [
                'type' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                'id' => 0,
                'websiteCode' => '',
                'storeCode' => '',
                'baseUrl' => $this->baseUrlFor(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0, null, $store),
                'label' => 'Default config',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: scope lookup failed', ['exception' => $e->getMessage()]);
            return ['error' => 'That store view does not exist.', 'type' => 'default', 'id' => 0, 'websiteCode' => '', 'storeCode' => '', 'baseUrl' => '', 'label' => ''];
        }
    }

    /**
     * Store-view base URLs live in core_config_data. Store::getBaseUrl() can
     * repeat the first store's host when called from the admin.
     *
     * @param mixed $store
     */
    private function baseUrlFor(string $scopeType, int $scopeId, ?int $websiteId, $store): string
    {
        $configured = $this->configuredBaseUrl($scopeType, $scopeId, $websiteId);
        if ($configured !== null) {
            return $configured;
        }

        return (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
    }

    private function configuredBaseUrl(string $scopeType, int $scopeId, ?int $websiteId): ?string
    {
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
                ->where('path IN (?)', ['web/secure/base_url', 'web/unsecure/base_url']);
            $rows = $connection->fetchAll($select);
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: could not read the store base URL');
            return null;
        }
        if (!is_array($rows)) {
            return null;
        }
        $secure = [];
        $unsecure = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = [
                'scope' => (string) ($row['scope'] ?? ''),
                'scope_id' => (int) ($row['scope_id'] ?? 0),
                'value' => (string) ($row['value'] ?? ''),
            ];
            if (($row['path'] ?? '') === 'web/secure/base_url') {
                $secure[] = $item;
            } else {
                $unsecure[] = $item;
            }
        }

        return ScopeBaseUrl::resolve($secure, $scopeType, $scopeId, $websiteId)
            ?? ScopeBaseUrl::resolve($unsecure, $scopeType, $scopeId, $websiteId);
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function read(string $path, array $target): ?string
    {
        $scope = $target['type'] === 'stores'
            ? ScopeInterface::SCOPE_STORE
            : ($target['type'] === 'websites' ? ScopeInterface::SCOPE_WEBSITE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $value = $this->scopeConfig->getValue($path, $scope, $target['id']);
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * @param array{type:string,id:int} $target
     */
    private function readSecret(string $path, array $target): ?string
    {
        $stored = $this->read($path, $target);
        if ($stored === null) {
            return null;
        }
        try {
            $plain = trim($this->encryptor->decrypt($stored));
        } catch (\Throwable $e) {
            $this->logger->error('Kiyoh Connect: stored secret could not be decrypted', ['path' => $path]);
            return null;
        }

        return $plain === '' ? null : $plain;
    }

    private function flushConfig(): void
    {
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
        $this->reinitableConfig->reinit();
    }

    private function logCall(string $step, ConnectCall $call): void
    {
        $context = ['status' => $call->status];
        if ($call->status === 0 && $call->logDetail !== '') {
            $context['detail'] = substr($call->logDetail, 0, 300);
        }
        $this->logger->error('Kiyoh Connect: ' . $step . ' failed', $context);
    }

    private function tokenShape(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9\-._~]{8,512}$/', $value) === 1;
    }

    /**
     * @return array{success:false,message:string}
     */
    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
