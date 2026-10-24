<?php

namespace Kiyoh\Reviews\Model\Connect;

/**
 * Called from Setup/Uninstall before local credentials are deleted.
 */
class UninstallRevoker
{
    /**
     * @var AdminConnectService
     */
    private $connect;

    public function __construct(AdminConnectService $connect)
    {
        $this->connect = $connect;
    }

    public function revokeAll(): void
    {
        $this->connect->revokeAllForUninstall();
    }
}
