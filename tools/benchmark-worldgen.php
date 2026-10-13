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
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

$iterations = filter_var($argv[1] ?? '24', FILTER_VALIDATE_INT);
if (!is_int($iterations) || $iterations < 4 || $iterations > 500) {
    fwrite(STDERR, "Usage: php tools/benchmark-worldgen.php [iterations:4-500]\n");
    exit(2);
}

$registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
$generator = new DefaultWorldGenerator(0, $registry);
$samples = [];
$checksum = '';
for ($index = 0; $index < $iterations; ++$index) {
    // A diagonal with gaps keeps every sample cold while still exercising cache reuse at shared borders.
    $position = new ChunkPosition(1000 + $index * 3, -1000 - $index * 5);
    $started = hrtime(true);
    $chunk = $generator->generate($position);
    $samples[] = (hrtime(true) - $started) / 1_000_000;
    $checksum = hash('xxh3', $checksum . serialize($chunk->populatedSections()));
}

$sorted = $samples;
sort($sorted, SORT_NUMERIC);
$percentile = static function (float $percentile) use ($sorted): float {
    $index = (int) ceil((count($sorted) - 1) * $percentile);

    return $sorted[$index];
};
$result = [
    'chunks' => count($samples),
    'mean_ms' => array_sum($samples) / count($samples),
    'p50_ms' => $percentile(0.50),
    'p95_ms' => $percentile(0.95),
    'minimum_ms' => min($samples),
    'maximum_ms' => max($samples),
    'checksum' => $checksum,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
