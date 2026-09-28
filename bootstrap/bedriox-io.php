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

use Bedriox\Server\Observability\Internal\LogWriterProcessProgram;
use Bedriox\Server\Persistence\Player\Internal\PlayerStorageProcessProgram;
use Bedriox\Server\Persistence\World\Internal\WorldStorageProcessProgram;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($argv[1] ?? '') === 'log') {
    exit(LogWriterProcessProgram::run($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''));
}
if (($argv[1] ?? '') === 'world') {
    exit(WorldStorageProcessProgram::run(
        $argv[2] ?? '',
        $argv[3] ?? '',
        $argv[4] ?? '',
        $argv[5] ?? '',
    ));
}
if (($argv[1] ?? '') === 'player') {
    exit(PlayerStorageProcessProgram::run(
        $argv[2] ?? '',
        $argv[3] ?? '',
        $argv[4] ?? '',
        $argv[5] ?? '',
    ));
}

exit(64);
