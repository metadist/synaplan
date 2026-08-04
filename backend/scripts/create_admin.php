#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once __DIR__.'/../vendor/autoload.php';

if ($argc > 1) {
    fwrite(
        STDERR,
        'Command-line credentials are no longer accepted. Set BOOTSTRAP_ADMIN_EMAIL and '.
        "BOOTSTRAP_ADMIN_PASSWORD, then run this script without arguments.\n"
    );

    exit(1);
}

$process = new Process([PHP_BINARY, __DIR__.'/../bin/console', 'app:bootstrap-admin']);
$process->setTimeout(null);

exit($process->run(static function (string $type, string $buffer): void {
    fwrite(Process::ERR === $type ? STDERR : STDOUT, $buffer);
}));
