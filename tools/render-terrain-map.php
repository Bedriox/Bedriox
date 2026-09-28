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

use Bedriox\Server\World\Generation\OverworldBiomeResolver;
use Bedriox\Server\World\Generation\OverworldTerrainSampler;
use Bedriox\Server\World\Generation\SeededNoise;

require dirname(__DIR__) . '/vendor/autoload.php';

$seed = filter_var($argv[1] ?? '0', FILTER_VALIDATE_INT);
$size = filter_var($argv[2] ?? '128', FILTER_VALIDATE_INT);
$spacing = filter_var($argv[3] ?? '16', FILTER_VALIDATE_INT);
if (!is_int($seed) || !is_int($size) || $size < 16 || $size > 512
    || !is_int($spacing) || $spacing < 1 || $spacing > 128) {
    fwrite(STDERR, "Usage: php tools/render-terrain-map.php [seed] [size:16-512] [spacing:1-128]\n");
    exit(2);
}

$outputDirectory = dirname(__DIR__) . '/build/terrain-diagnostics';
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Unable to create terrain diagnostics directory.\n");
    exit(1);
}

$terrain = new OverworldTerrainSampler(new SeededNoise($seed));
$biomes = new OverworldBiomeResolver();
$heightPixels = '';
$biomePixels = '';
$minimum = PHP_INT_MAX;
$maximum = PHP_INT_MIN;
$counts = [];
$origin = -intdiv($size * $spacing, 2);
for ($pixelZ = 0; $pixelZ < $size; ++$pixelZ) {
    for ($pixelX = 0; $pixelX < $size; ++$pixelX) {
        $sample = $terrain->sample($origin + $pixelX * $spacing, $origin + $pixelZ * $spacing);
        $height = $sample->surfaceHeight;
        $minimum = min($minimum, $height);
        $maximum = max($maximum, $height);
        $shade = max(0, min(255, (int) round(($height + 64) * 255 / 264)));
        $heightPixels .= chr($shade) . chr($shade) . chr($shade);
        $identifier = $biomes->resolve($sample)->identifier;
        $counts[$identifier] = ($counts[$identifier] ?? 0) + 1;
        [$red, $green, $blue] = match ($identifier) {
            'minecraft:deep_ocean' => [12, 43, 104],
            'minecraft:ocean' => [22, 78, 152],
            'minecraft:river' => [42, 111, 190],
            'minecraft:beach' => [225, 211, 142],
            'minecraft:stone_beach' => [118, 118, 112],
            'minecraft:desert' => [218, 194, 104],
            'minecraft:forest' => [31, 104, 45],
            'minecraft:birch_forest' => [75, 139, 71],
            'minecraft:taiga' => [48, 91, 74],
            'minecraft:savanna' => [164, 177, 76],
            'minecraft:ice_plains' => [218, 235, 239],
            'minecraft:snowy_slopes' => [235, 243, 245],
            'minecraft:jagged_peaks' => [208, 211, 214],
            'minecraft:extreme_hills' => [99, 122, 89],
            default => [91, 157, 68],
        };
        $biomePixels .= chr($red) . chr($green) . chr($blue);
    }
}

$prefix = $outputDirectory . '/seed-' . $seed . '-' . $size . 'x' . $size;
$header = "P6\n$size $size\n255\n";
file_put_contents($prefix . '-height.ppm', $header . $heightPixels, LOCK_EX);
file_put_contents($prefix . '-biomes.ppm', $header . $biomePixels, LOCK_EX);
ksort($counts, SORT_STRING);
file_put_contents($prefix . '-summary.json', json_encode([
    'seed' => $seed,
    'size' => $size,
    'spacing' => $spacing,
    'minimum_height' => $minimum,
    'maximum_height' => $maximum,
    'biomes' => $counts,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);

fwrite(STDOUT, "Terrain diagnostics written to $outputDirectory\n");
