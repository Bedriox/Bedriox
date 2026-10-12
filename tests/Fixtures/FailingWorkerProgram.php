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

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
    $endpoint = $argv[4] ?? '';
    $token = hex2bin($argv[5] ?? '');
    $connection = is_string($token) ? @stream_socket_client($endpoint, $errorCode, $errorMessage, 5) : false;
    if (!is_resource($connection) || fwrite($connection, $token) !== strlen($token)) {
        exit(68);
    }
    fclose($connection);

    exit(70);
}

exit(64);
