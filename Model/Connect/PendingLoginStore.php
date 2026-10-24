<?php

namespace Kiyoh\Reviews\Model\Connect;

interface PendingLoginStore
{
    public function put(PendingLogin $login): void;

    /**
     * Returns the login once. A second call with the same state returns null.
     */
    public function consume(string $state, int $now): ?PendingLogin;

    public function deleteExpired(int $now): void;
}
