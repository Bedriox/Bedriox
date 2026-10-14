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
use Bedriox\Server\World\Generation\Vegetation\OverworldVegetationPlanner;
use Bedriox\Server\World\Generation\Vegetation\VegetationProfileCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$seed = isset($argv[1]) ? (int) $argv[1] : 0;
$searchRadius = isset($argv[2]) ? max(512, min(16_384, (int) $argv[2])) : 8_192;
$sampleRadius = isset($argv[3]) ? max(64, min(512, (int) $argv[3])) : 128;
$noise = new SeededNoise($seed);
$terrain = new OverworldTerrainSampler($noise);
$biomes = new OverworldBiomeResolver();
$biomeAt = static fn(int $x, int $z): string => $biomes->resolve($terrain->sample($x, $z))->identifier;
$heightAt = static fn(int $x, int $z): int => $terrain->sample($x, $z)->surfaceHeight;

$representatives = [
    'bamboo_jungle' => 'minecraft:bamboo_jungle',
    'birch_forest' => 'minecraft:birch_forest',
    'cherry_grove' => 'minecraft:cherry_grove',
    'dappled_forest' => 'minecraft:dappled_forest',
    'dark_forest' => 'minecraft:roofed_forest',
    'flower_forest' => 'minecraft:flower_forest',
    'forest' => 'minecraft:forest',
    'jungle' => 'minecraft:jungle',
    'meadow_trees' => 'minecraft:meadow',
    'old_growth_taiga' => 'minecraft:mega_taiga',
    'pale_garden' => 'minecraft:pale_garden',
    'plains_oaks' => 'minecraft:plains',
    'savanna' => 'minecraft:savanna',
    'swamp' => 'minecraft:swampland',
    'taiga' => 'minecraft:taiga',
    'wooded_mountains' => 'minecraft:extreme_hills_plus_trees',
    'wooded_badlands' => 'minecraft:mesa_plateau_stone',
];

$centers = [];
$step = 32;
for ($ring = 0; $ring <= $searchRadius; $ring += $step) {
    for ($z = -$ring; $z <= $ring; $z += $step) {
        for ($x = -$ring; $x <= $ring; $x += $step) {
            if ($ring !== 0 && abs($x) !== $ring && abs($z) !== $ring) {
                continue;
            }
            $biome = $biomeAt($x, $z);
            $profile = VegetationProfileCatalog::forBiome($biome);
            if ($profile !== null && !isset($centers[$profile->name])) {
                $centers[$profile->name] = [$x, $z, $biome];
            }
        }
    }
    if (count($centers) >= count($representatives)) {
        break;
    }
}
ksort($centers);

$report = [];
foreach ($representatives as $profileName => $representativeBiome) {
    $center = $centers[$profileName] ?? null;
    $profilePlanner = new OverworldVegetationPlanner($noise);
    $plans = $profilePlanner->plansAffecting(
        -$sampleRadius,
        $sampleRadius,
        -$sampleRadius,
        $sampleRadius,
        static fn(int $x, int $z): string => $representativeBiome,
        static fn(int $x, int $z): int => 70,
    );
    $plans = array_values(array_filter(
        $plans,
        static fn($plan): bool => $plan->profile === $profileName
            && abs($plan->x) <= $sampleRadius
            && abs($plan->z) <= $sampleRadius,
    ));
    $archetypes = [];
    $shapeSignatures = [];
    $nearestDistances = [];
    $occupiedCells = [];
    foreach ($plans as $index => $plan) {
        $archetypes[$plan->archetype->value] = ($archetypes[$plan->archetype->value] ?? 0) + 1;
        $shapeSignatures[$plan->archetype->value . ':' . $plan->height . ':' . $plan->rotation . ':' . $plan->shapeSeed] = true;
        $occupiedCells[intdiv($plan->x + $sampleRadius, 32) . ':' . intdiv($plan->z + $sampleRadius, 32)] = true;
        $nearestSquared = PHP_INT_MAX;
        foreach ($plans as $otherIndex => $other) {
            if ($index === $otherIndex) {
                continue;
            }
            $distanceX = $plan->x - $other->x;
            $distanceZ = $plan->z - $other->z;
            $nearestSquared = min($nearestSquared, $distanceX * $distanceX + $distanceZ * $distanceZ);
        }
        if ($nearestSquared !== PHP_INT_MAX) {
            $nearestDistances[] = sqrt((float) $nearestSquared);
        }
    }
    sort($nearestDistances, SORT_NUMERIC);
    ksort($archetypes);
    $cellWidth = (int) ceil(($sampleRadius * 2 + 1) / 32);
    $report[$profileName] = [
        'test_center' => $center === null ? null : [$center[0], $heightAt($center[0], $center[1]) + 12, $center[1]],
        'center_biome' => $center[2] ?? null,
        'trees' => count($plans),
        'archetypes' => $archetypes,
        'archetype_count' => count($archetypes),
        'unique_plan_percent' => $plans === [] ? 0.0 : count($shapeSignatures) * 100.0 / count($plans),
        'nearest_minimum' => $nearestDistances[0] ?? null,
        'nearest_median' => $nearestDistances === [] ? null : $nearestDistances[intdiv(count($nearestDistances), 2)],
        'nearest_maximum' => $nearestDistances === [] ? null : $nearestDistances[array_key_last($nearestDistances)],
        'occupied_32_block_cells_percent' => count($occupiedCells) * 100.0 / ($cellWidth * $cellWidth),
    ];
}

fwrite(STDOUT, json_encode([
    'seed' => $seed,
    'search_radius' => $searchRadius,
    'sample_radius' => $sampleRadius,
    'profiles_found' => count($report),
    'profiles' => $report,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
