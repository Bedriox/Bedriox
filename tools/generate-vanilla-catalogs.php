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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Tools\VanillaCatalogGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/VanillaCatalogGenerator.php';

$arguments = array_slice($argv, 1);
$check = $arguments === ['--check'];
if ($arguments !== [] && !$check) {
    fwrite(STDERR, 'Usage: php tools/generate-vanilla-catalogs.php [--check]' . PHP_EOL);
    exit(2);
}

$data = BedrockDataSet::bundled();
$blockIdentifiers = [];
foreach ($data->blockStateRegistry()->states() as $state) {
    $blockIdentifiers[$state->identifier()] = true;
}

$catalogs = [
    dirname(__DIR__) . '/src/Api/World/VanillaBlocks.php' => VanillaCatalogGenerator::render(
        'Bedriox\\Api\\World',
        'VanillaBlocks',
        array_keys($blockIdentifiers),
    ),
    dirname(__DIR__) . '/src/Api/Inventory/VanillaItems.php' => VanillaCatalogGenerator::render(
        'Bedriox\\Api\\Inventory',
        'VanillaItems',
        array_keys($data->itemNetworkRegistry()->definitions()),
    ),
];

$stale = [];
foreach ($catalogs as $path => $contents) {
    if (VanillaCatalogGenerator::isCurrent($path, $contents)) {
        continue;
    }
    if ($check) {
        $stale[] = str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1));
        continue;
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        fwrite(STDERR, "Unable to write {$path}." . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, 'Updated ' . str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1)) . PHP_EOL);
}

if ($stale !== []) {
    foreach ($stale as $path) {
        fwrite(STDERR, "Generated catalog is stale: {$path}" . PHP_EOL);
    }
    exit(1);
}

if ($check) {
    fwrite(STDOUT, 'Vanilla block and item catalogs are current.' . PHP_EOL);
}
