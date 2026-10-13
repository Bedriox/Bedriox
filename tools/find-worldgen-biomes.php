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
use Bedriox\Server\World\DefaultWorldGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

$seed = isset($argv[1]) ? (int) $argv[1] : 0;
$radius = isset($argv[2]) ? max(512, min(16_384, (int) $argv[2])) : 8_192;
$step = isset($argv[3]) ? max(16, min(256, (int) $argv[3])) : 64;
$data = BedrockDataSet::bundled();
$generator = new DefaultWorldGenerator(
    $seed,
    new BlockStateRegistry($data->blockStateRegistry()->states()),
);
$families = [
    'plains' => ['minecraft:plains', 'minecraft:sunflower_plains'],
    'forest' => ['minecraft:forest', 'minecraft:flower_forest', 'minecraft:birch_forest'],
    'taiga' => ['minecraft:taiga', 'minecraft:mega_taiga', 'minecraft:cold_taiga'],
    'savanna' => ['minecraft:savanna', 'minecraft:savanna_plateau'],
    'desert' => ['minecraft:desert', 'minecraft:desert_hills'],
    'jungle' => ['minecraft:jungle', 'minecraft:bamboo_jungle', 'minecraft:jungle_hills'],
    'swamp' => ['minecraft:swampland', 'minecraft:mangrove_swamp'],
    'meadow' => ['minecraft:meadow', 'minecraft:cherry_grove'],
    'mountains' => ['minecraft:extreme_hills', 'minecraft:jagged_peaks', 'minecraft:frozen_peaks', 'minecraft:stony_peaks'],
    'badlands' => ['minecraft:mesa', 'minecraft:mesa_plateau', 'minecraft:mesa_bryce'],
    'beach' => ['minecraft:beach', 'minecraft:stone_beach', 'minecraft:cold_beach'],
    'river' => ['minecraft:river', 'minecraft:frozen_river'],
    'ocean' => ['minecraft:ocean', 'minecraft:deep_ocean', 'minecraft:cold_ocean'],
    'warm ocean' => ['minecraft:warm_ocean', 'minecraft:lukewarm_ocean'],
    'frozen ocean' => ['minecraft:frozen_ocean', 'minecraft:deep_frozen_ocean'],
    'mushroom island' => ['minecraft:mushroom_island', 'minecraft:mushroom_island_shore'],
];
$found = [];
for ($ring = 0; $ring <= $radius && count($found) < count($families); $ring += $step) {
    for ($z = -$ring; $z <= $ring; $z += $step) {
        for ($x = -$ring; $x <= $ring; $x += $step) {
            if ($ring !== 0 && abs($x) !== $ring && abs($z) !== $ring) {
                continue;
            }
            $biome = $generator->biomeAt($x, $z)->identifier;
            foreach ($families as $family => $identifiers) {
                if (!isset($found[$family]) && in_array($biome, $identifiers, true)) {
                    $found[$family] = [$x, $generator->surfaceHeight($x, $z) + 4, $z, $biome];
                }
            }
        }
    }
}

foreach ($families as $family => $_) {
    $match = $found[$family] ?? null;
    if ($match === null) {
        printf("%-16s not found within %d blocks\n", $family, $radius);
        continue;
    }
    printf("%-16s /tp @s %d %d %d  (%s)\n", $family, $match[0], $match[1], $match[2], $match[3]);
}
