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

use Bedriox\Server\Environment\RuntimeArchiveInstaller;
use Bedriox\Server\Environment\RuntimeArtifactExpectation;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || count($arguments) !== 2 || !is_string($arguments[1] ?? null) || $arguments[1] === '') {
    fwrite(STDERR, 'Usage: php tools/install-runtime.php <local-runtime-archive>' . PHP_EOL);
    exit(64);
}

$machine = strtolower(php_uname('m'));
$architecture = match (true) {
    in_array($machine, ['x86_64', 'amd64'], true) => 'x86_64',
    in_array($machine, ['arm64', 'aarch64'], true) => 'arm64',
    default => null,
};
$platform = match (PHP_OS_FAMILY) {
    'Windows' => 'windows',
    'Darwin' => 'macos',
    'Linux' => 'linux',
    default => null,
};
if ($architecture === null || $platform === null || PHP_INT_SIZE !== 8) {
    fwrite(STDERR, 'This host does not have a supported 64-bit Bedriox runtime target.' . PHP_EOL);
    exit(1);
}
$target = $platform . '-' . $architecture;

try {
    $expected = RuntimeArtifactExpectation::fromLockFile($root . '/bedriox.lock.json', $target);
    (new RuntimeArchiveInstaller())->install($arguments[1], $root, $expected);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Runtime installation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Installed locked Bedriox runtime for ' . $target . '.' . PHP_EOL);
