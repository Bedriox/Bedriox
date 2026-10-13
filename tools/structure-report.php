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
use Bedriox\Server\World\Generation\Structure\Location\StructureValidationReport;

require dirname(__DIR__) . '/vendor/autoload.php';

$seed = 0;
$json = false;
$outputPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--json') {
        $json = true;
        continue;
    }
    if (preg_match('/^--seed=(-?\d+)$/D', $argument, $matches) === 1) {
        $seed = (int) $matches[1];
        continue;
    }
    if (preg_match('/^--output=(.+)$/D', $argument, $matches) === 1 && !str_contains($matches[1], "\0")) {
        $outputPath = $matches[1];
        continue;
    }
    fwrite(STDERR, "Usage: php tools/structure-report.php [--seed=<integer>] [--json] [--output=<path>]\n");
    exit(2);
}

$data = BedrockDataSet::bundled();
$report = new StructureValidationReport(
    $seed,
    new BlockStateRegistry($data->blockStateRegistry()->states()),
);
$rows = array_map(static fn($entry): array => $entry->toArray(), $report->entries());
if ($json) {
    $rendered = json_encode(
        ['seed' => $seed, 'structures' => $rows],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
} else {
    $rendered = "Structure validation coordinates (seed {$seed})\n";
    foreach ($rows as $row) {
        $rendered .= sprintf(
            "%-18s %-18s %-20s %s%s\n",
            $row['identifier'],
            $row['variant'],
            $row['dimension'],
            $row['teleport'],
            $row['condition'] === 'terrain_generation' ? '' : ' [' . $row['condition'] . ']',
        );
    }
}
if ($outputPath === null) {
    echo $rendered;
    exit(0);
}
if (file_put_contents($outputPath, $rendered, LOCK_EX) !== strlen($rendered)) {
    fwrite(STDERR, "Unable to write structure report.\n");
    exit(1);
}
