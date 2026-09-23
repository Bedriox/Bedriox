#!/usr/bin/env php
<?php

declare(strict_types=1);

use Bedriox\Server\Worker\Internal\BrokerProgram;
use Bedriox\Server\Worker\Internal\ComputeWorkerProgram;

require dirname(__DIR__) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
if ($mode === 'broker') {
    exit(BrokerProgram::run(
        (int) ($argv[2] ?? 0),
        $argv[3] ?? '',
        $argv[4] ?? '',
        __FILE__,
        $argv[5] ?? null,
        $argv[6] ?? null,
    ));
}
if ($mode === 'compute') {
    exit(ComputeWorkerProgram::run($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? null, $argv[5] ?? null));
}

exit(64);
