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

namespace Bedriox\Server\Tests\World\Generation\Vegetation;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use PHPUnit\Framework\TestCase;

final class GeneratedForestDiversityTest extends TestCase
{
    public function testRenderedForestHasVariedSilhouettesBranchesSpacingAndClearings(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new DefaultWorldGenerator(9_123, $states);
        $chunks = [];
        for ($chunkZ = -4; $chunkZ <= 4; ++$chunkZ) {
            for ($chunkX = -4; $chunkX <= 4; ++$chunkX) {
                $chunks[$chunkX . ':' . $chunkZ] = $generator->generate(new ChunkPosition($chunkX, $chunkZ));
            }
        }

        $roots = [];
        for ($z = -48; $z <= 48; ++$z) {
            for ($x = -48; $x <= 48; ++$x) {
                $ground = null;
                for ($y = Chunk::MIN_Y + 1; $y < Chunk::MAX_Y; ++$y) {
                    $candidate = $states->state(self::stateAt($chunks, $x, $y, $z));
                    if (!self::isLog($candidate->identifier())
                        || ($candidate->properties()['pillar_axis'] ?? 'y') !== 'y') {
                        continue;
                    }
                    $below = self::identifierAt($chunks, $states, $x, $y - 1, $z);
                    if (in_array($below, [
                        'minecraft:dirt', 'minecraft:grass_block', 'minecraft:coarse_dirt',
                        'minecraft:podzol', 'minecraft:mud', 'minecraft:moss_block',
                        'minecraft:pale_moss_block', 'minecraft:mycelium', 'minecraft:dirt_with_roots',
                    ], true)) {
                        $ground = $y - 1;
                        break;
                    }
                }
                if (!is_int($ground)) {
                    continue;
                }
                if (self::isLog(self::identifierAt($chunks, $states, $x - 1, $ground + 1, $z))
                    || self::isLog(self::identifierAt($chunks, $states, $x, $ground + 1, $z - 1))) {
                    continue;
                }
                $roots[] = [$x, $ground, $z];
            }
        }

        self::assertGreaterThanOrEqual(20, count($roots));
        $signatures = [];
        $branched = 0;
        $occupiedCells = [];
        $minimumDistanceSquared = PHP_INT_MAX;
        foreach ($roots as $index => [$rootX, $ground, $rootZ]) {
            $layerLogs = [];
            $layerLeaves = [];
            $horizontalLogs = 0;
            for ($y = $ground + 1; $y <= $ground + 20; ++$y) {
                $relativeY = $y - $ground;
                for ($z = $rootZ - 5; $z <= $rootZ + 5; ++$z) {
                    for ($x = $rootX - 5; $x <= $rootX + 5; ++$x) {
                        $state = self::stateAt($chunks, $x, $y, $z);
                        $canonical = $states->state($state);
                        if (self::isLog($canonical->identifier())) {
                            $layerLogs[$relativeY] = ($layerLogs[$relativeY] ?? 0) + 1;
                            if (($canonical->properties()['pillar_axis'] ?? 'y') !== 'y') {
                                ++$horizontalLogs;
                            }
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
            $occupiedCells[intdiv($rootX + 48, 16) . ':' . intdiv($rootZ + 48, 16)] = true;
            foreach (array_slice($roots, $index + 1) as [$otherX, , $otherZ]) {
                $minimumDistanceSquared = min(
                    $minimumDistanceSquared,
                    ($rootX - $otherX) ** 2 + ($rootZ - $otherZ) ** 2,
                );
            }
        }

        if ($signatures === []) {
            self::fail('The generated forest did not contain any measurable tree silhouettes.');
        }
        $dominantSignature = max($signatures);
        self::assertGreaterThanOrEqual(12, count($signatures));
        self::assertLessThanOrEqual(0.25, $dominantSignature / count($roots));
        self::assertGreaterThanOrEqual(0.45, $branched / count($roots));
        self::assertGreaterThanOrEqual(16, $minimumDistanceSquared);
        self::assertLessThanOrEqual(39, count($occupiedCells), 'At least 20% of sampled forest cells must remain clear.');
    }

    /** @param array<string, Chunk> $chunks */
    private static function identifierAt(array $chunks, BlockStateRegistry $states, int $x, int $y, int $z): string
    {
        return $states->state(self::stateAt($chunks, $x, $y, $z))->identifier();
    }

    /** @param array<string, Chunk> $chunks */
    private static function stateAt(array $chunks, int $x, int $y, int $z): \Bedriox\Server\World\Block\InternalBlockStateId
    {
        $chunkX = self::floorDiv($x, 16);
        $chunkZ = self::floorDiv($z, 16);
        $chunk = $chunks[$chunkX . ':' . $chunkZ] ?? null;
        if (!$chunk instanceof Chunk) {
            throw new \LogicException("Missing generated chunk $chunkX:$chunkZ.");
        }

        return $chunk->blockStateAt($x - $chunkX * 16, $y, $z - $chunkZ * 16);
    }

    private static function isLog(string $identifier): bool
    {
        return str_ends_with($identifier, '_log');
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
