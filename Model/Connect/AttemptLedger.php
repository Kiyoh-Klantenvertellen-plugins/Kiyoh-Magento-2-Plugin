<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * In-memory single-use pending logins. The database store follows the same rule.
 */
final class AttemptLedger implements PendingLoginStore
{
    /**
     * @var array<string, PendingLogin>
     */
    private $rows = [];

    public function put(PendingLogin $login): void
    {
        $this->rows[$login->state] = $login;
    }

    public function peek(string $state, int $now): ?PendingLogin
    {
        if (!isset($this->rows[$state])) {
            return null;
        }
        $login = $this->rows[$state];
        if (!$login->isUsable($now) || !hash_equals($login->state, $state)) {
            return null;
        }

        return $login;
    }

    public function consume(string $state, int $now): ?PendingLogin
    {
        $login = $this->peek($state, $now);
        unset($this->rows[$state]);

        return $login;
    }

    public function deleteExpired(int $now): void
    {
        foreach ($this->rows as $state => $login) {
            if (!$login->isUsable($now)) {
                unset($this->rows[$state]);
            }
        }
    }
}
