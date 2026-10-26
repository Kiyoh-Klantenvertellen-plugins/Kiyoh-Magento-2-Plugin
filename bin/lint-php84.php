#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/Dev/Php84Lint.php';

use Kiyoh\Reviews\Dev\Php84Lint;

$root = dirname(__DIR__);
$requirePhp84 = in_array('--require-php84', $argv, true);
if ($requirePhp84 && PHP_VERSION_ID < 80400) {
    fwrite(STDERR, 'PHP 8.4 is required for the php -l deprecation check. Running ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

$findings = Php84Lint::scan($root);
if ($findings !== []) {
    fwrite(STDERR, 'PHP 8.4 lint failed:' . PHP_EOL);
    foreach ($findings as $finding) {
        fwrite(STDERR, $finding . PHP_EOL);
    }
    exit(1);
}

if (PHP_VERSION_ID >= 80400) {
    $mode = 'token scan and php -l reported no deprecations';
} else {
    $mode = 'token scan found no implicit-nullable parameters; php -l deprecation check needs PHP 8.4 (this is ' . PHP_VERSION . ')';
}
fwrite(STDOUT, 'PHP 8.4 lint ok (' . $mode . ').' . PHP_EOL);
exit(0);
