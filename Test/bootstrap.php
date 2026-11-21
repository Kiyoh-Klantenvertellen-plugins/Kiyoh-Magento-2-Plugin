<?php

define('KIYOH_MODULE_ROOT', dirname(__DIR__));

require KIYOH_MODULE_ROOT . '/Test/Stubs/MagentoSalesOrderInterface.php';
require KIYOH_MODULE_ROOT . '/Test/Stubs/MagentoFramework.php';
require KIYOH_MODULE_ROOT . '/Test/Stubs/MagentoConfig.php';
require KIYOH_MODULE_ROOT . '/Test/Stubs/MagentoJson.php';
require KIYOH_MODULE_ROOT . '/Test/Stubs/MagentoStore.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Kiyoh\\Reviews\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = KIYOH_MODULE_ROOT . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
