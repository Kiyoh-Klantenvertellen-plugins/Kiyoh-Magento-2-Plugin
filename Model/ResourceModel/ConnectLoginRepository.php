<?php

namespace Kiyoh\Reviews\Model\ResourceModel;

use Kiyoh\Reviews\Model\Connect\PendingLogin;
use Kiyoh\Reviews\Model\Connect\PendingLoginStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Single-use PKCE login state. The verifier is encrypted at rest.
 * Same consume rule as Model/Connect/AttemptLedger.
 */
class ConnectLoginRepository implements PendingLoginStore
{
    private const TABLE = 'kiyoh_reviews_connect_login';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    public function __construct(ResourceConnection $resource, EncryptorInterface $encryptor)
    {
        $this->resource = $resource;
        $this->encryptor = $encryptor;
    }

    public function put(PendingLogin $login): void
    {
        $this->deleteExpired($login->expiresAt > 0 ? time() : 0);
        $json = json_encode($login->toArray(), JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to store the Kiyoh-Connect sign-in');
        }

        $this->resource->getConnection()->insertOnDuplicate(
            $this->table(),
            [
                'state' => $login->state,
                'payload' => $this->encryptor->encrypt($json),
                'expires_at' => $login->expiresAt,
            ],
            ['payload', 'expires_at']
        );
    }

    public function consume(string $state, int $now): ?PendingLogin
    {
        $connection = $this->resource->getConnection();
        $table = $this->table();
        $connection->beginTransaction();
        try {
            $select = $connection->select()
                ->from($table, ['payload', 'expires_at'])
                ->where('state = ?', $state)
                ->forUpdate(true);
            $row = $connection->fetchRow($select);
            if (!is_array($row)) {
                $connection->commit();
                return null;
            }
            $connection->delete($table, ['state = ?' => $state]);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $payload = $row['payload'] ?? null;
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        try {
            $json = $this->encryptor->decrypt($payload);
        } catch (\Throwable $e) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $login = PendingLogin::fromArray($data);
        if ($login === null || !$login->isUsable($now)) {
            return null;
        }
        if (strlen($login->state) !== strlen($state) || !hash_equals($login->state, $state)) {
            return null;
        }

        return $login;
    }

    public function deleteExpired(int $now): void
    {
        $this->resource->getConnection()->delete($this->table(), ['expires_at < ?' => $now]);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
