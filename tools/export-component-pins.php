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

use Bedriox\Tools\ComponentPinExporter;

require __DIR__ . '/ComponentPinExporter.php';

$path = dirname(__DIR__) . '/bedriox.lock.json';
$json = file_get_contents($path);
if ($json === false) {
    fwrite(STDERR, 'Unable to read bedriox.lock.json.' . PHP_EOL);
    exit(1);
}

try {
    /** @var mixed $decoded */
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('bedriox.lock.json root must be an object.');
    }
    /** @var array<string, mixed> $decoded */
    $pins = ComponentPinExporter::export($decoded);
} catch (JsonException|InvalidArgumentException|RuntimeException $exception) {
    fwrite(STDERR, 'Unable to export CI component pins: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($pins as $name => $commit) {
    fwrite(STDOUT, "{$name}={$commit}" . PHP_EOL);
}
