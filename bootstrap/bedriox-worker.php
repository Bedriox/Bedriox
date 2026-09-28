#!/usr/bin/env php
<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
