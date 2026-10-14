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
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;

$loader = require dirname(__DIR__) . '/vendor/autoload.php';
if (!$loader instanceof Composer\Autoload\ClassLoader) {
    throw new RuntimeException('Composer did not return its autoloader instance.');
}
if (isset($argv[5]) && is_dir($argv[5] . DIRECTORY_SEPARATOR . 'src')) {
    $loader->setPsr4('Bedriox\\Server\\', $argv[5] . DIRECTORY_SEPARATOR . 'src');
}

$seed = isset($argv[1]) ? (int) $argv[1] : 0;
$centerX = isset($argv[2]) ? (int) $argv[2] : 960;
$centerZ = isset($argv[3]) ? (int) $argv[3] : -64;
$radius = isset($argv[4]) ? max(32, min(192, (int) $argv[4])) : 64;
$states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
$generator = new DefaultWorldGenerator($seed, $states);
$minimumX = $centerX - $radius;
$maximumX = $centerX + $radius;
$minimumZ = $centerZ - $radius;
$maximumZ = $centerZ + $radius;
$chunkMargin = 1;
$minimumChunkX = floorDiv($minimumX, 16) - $chunkMargin;
$maximumChunkX = floorDiv($maximumX, 16) + $chunkMargin;
$minimumChunkZ = floorDiv($minimumZ, 16) - $chunkMargin;
$maximumChunkZ = floorDiv($maximumZ, 16) + $chunkMargin;
$chunks = [];
$started = hrtime(true);
for ($chunkZ = $minimumChunkZ; $chunkZ <= $maximumChunkZ; ++$chunkZ) {
    for ($chunkX = $minimumChunkX; $chunkX <= $maximumChunkX; ++$chunkX) {
        $chunks[$chunkX . ':' . $chunkZ] = $generator->generate(new ChunkPosition($chunkX, $chunkZ));
    }
}
$generationMilliseconds = (hrtime(true) - $started) / 1_000_000;

$roots = [];
$biomes = [];
for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
    for ($x = $minimumX; $x <= $maximumX; ++$x) {
        $biome = $generator->biomeAt($x, $z)->identifier;
        $biomes[$biome] = ($biomes[$biome] ?? 0) + 1;
        $ground = null;
        $identifier = null;
        for ($y = Chunk::MIN_Y + 1; $y < Chunk::MAX_Y; ++$y) {
            $candidate = $states->state(stateAt($chunks, $x, $y, $z));
            if (!isLog($candidate->identifier())
                || ($candidate->properties()['pillar_axis'] ?? 'y') !== 'y'
                || !isTreeSubstrate(identifierAt($chunks, $states, $x, $y - 1, $z))) {
                continue;
            }
            $ground = $y - 1;
            $identifier = $candidate->identifier();
            break;
        }
        if (!is_int($ground) || !is_string($identifier)) {
            continue;
        }
        // Multi-block trunks expose multiple adjacent valid root columns. Keep the
        // north-west root only so density and spacing statistics count each tree once.
        if (isLog(identifierAt($chunks, $states, $x - 1, $ground + 1, $z))
            || isLog(identifierAt($chunks, $states, $x, $ground + 1, $z - 1))) {
            continue;
        }
        $roots[] = [$x, $ground, $z, $identifier];
    }
}

$species = [];
$signatures = [];
$branched = 0;
$occupiedCells = [];
$nearestDistances = [];
foreach ($roots as $index => [$rootX, $ground, $rootZ, $speciesIdentifier]) {
    $species[$speciesIdentifier] = ($species[$speciesIdentifier] ?? 0) + 1;
    $layerLogs = [];
    $layerLeaves = [];
    $horizontalLogs = 0;
    for ($y = $ground + 1; $y <= $ground + 20; ++$y) {
        $relativeY = $y - $ground;
        for ($z = $rootZ - 5; $z <= $rootZ + 5; ++$z) {
            for ($x = $rootX - 5; $x <= $rootX + 5; ++$x) {
                $canonical = $states->state(stateAt($chunks, $x, $y, $z));
                if (isLog($canonical->identifier())) {
                    $layerLogs[$relativeY] = ($layerLogs[$relativeY] ?? 0) + 1;
                    $horizontalLogs += (int) (($canonical->properties()['pillar_axis'] ?? 'y') !== 'y');
                } elseif (str_ends_with($canonical->identifier(), '_leaves')) {
                    $layerLeaves[$relativeY] = ($layerLeaves[$relativeY] ?? 0) + 1;
                }
            }
        }
    }
    ksort($layerLogs);
    ksort($layerLeaves);
    $signature = hash('xxh3', serialize([$layerLogs, $layerLeaves, $horizontalLogs]));
    $signatures[$signature] = ($signatures[$signature] ?? 0) + 1;
    $branched += (int) ($horizontalLogs > 0);
    $occupiedCells[floorDiv($rootX - $minimumX, 16) . ':' . floorDiv($rootZ - $minimumZ, 16)] = true;
    $nearestSquared = PHP_INT_MAX;
    foreach ($roots as $otherIndex => [$otherX, , $otherZ]) {
        if ($index === $otherIndex) {
            continue;
        }
        $nearestSquared = min($nearestSquared, ($rootX - $otherX) ** 2 + ($rootZ - $otherZ) ** 2);
    }
    if ($nearestSquared !== PHP_INT_MAX) {
        $nearestDistances[] = sqrt((float) $nearestSquared);
    }
}
sort($nearestDistances, SORT_NUMERIC);
arsort($signatures);
ksort($species);
arsort($biomes);
$diameter = $radius * 2 + 1;
$cellWidth = (int) ceil($diameter / 16);
$treeCount = count($roots);

fwrite(STDOUT, json_encode([
    'seed' => $seed,
    'center' => [$centerX, $centerZ],
    'radius' => $radius,
    'generated_chunks' => count($chunks),
    'generation_ms' => $generationMilliseconds,
    'dominant_biomes' => array_slice($biomes, 0, 8, true),
    'trees' => $treeCount,
    'trees_per_chunk_area' => $treeCount / (($diameter * $diameter) / 256),
    'species' => $species,
    'unique_rendered_signatures' => count($signatures),
    'unique_rendered_percent' => $treeCount === 0 ? 0.0 : count($signatures) * 100.0 / $treeCount,
    'dominant_signature_percent' => $treeCount === 0 ? 0.0 : ((int) reset($signatures)) * 100.0 / $treeCount,
    'branched_percent' => $treeCount === 0 ? 0.0 : $branched * 100.0 / $treeCount,
    'nearest_minimum' => $nearestDistances[0] ?? null,
    'nearest_median' => $nearestDistances === [] ? null : $nearestDistances[intdiv(count($nearestDistances), 2)],
    'nearest_maximum' => $nearestDistances === [] ? null : $nearestDistances[array_key_last($nearestDistances)],
    'occupied_16_block_cells_percent' => count($occupiedCells) * 100.0 / ($cellWidth * $cellWidth),
    'empty_16_block_cells_percent' => 100.0 - count($occupiedCells) * 100.0 / ($cellWidth * $cellWidth),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

/** @param array<string, Chunk> $chunks */
function identifierAt(array $chunks, BlockStateRegistry $states, int $x, int $y, int $z): string
{
    return $states->state(stateAt($chunks, $x, $y, $z))->identifier();
}

/** @param array<string, Chunk> $chunks */
function stateAt(array $chunks, int $x, int $y, int $z): InternalBlockStateId
{
    $chunkX = floorDiv($x, 16);
    $chunkZ = floorDiv($z, 16);
    $chunk = $chunks[$chunkX . ':' . $chunkZ] ?? null;
    if (!$chunk instanceof Chunk) {
        throw new LogicException("Missing generated chunk $chunkX:$chunkZ.");
    }

    return $chunk->blockStateAt($x - $chunkX * 16, $y, $z - $chunkZ * 16);
}

function isLog(string $identifier): bool
{
    return str_ends_with($identifier, '_log');
}

function isTreeSubstrate(string $identifier): bool
{
    return in_array($identifier, [
        'minecraft:dirt', 'minecraft:grass_block', 'minecraft:coarse_dirt',
        'minecraft:podzol', 'minecraft:mud', 'minecraft:moss_block',
        'minecraft:pale_moss_block', 'minecraft:mycelium', 'minecraft:dirt_with_roots',
    ], true);
}

function floorDiv(int $value, int $divisor): int
{
    $quotient = intdiv($value, $divisor);
    return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
}
