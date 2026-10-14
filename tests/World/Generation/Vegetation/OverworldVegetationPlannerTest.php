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

use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Vegetation\OverworldVegetationPlanner;
use Bedriox\Server\World\Generation\Vegetation\VegetationProfileCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverworldVegetationPlannerTest extends TestCase
{
    #[DataProvider('representativeBiomes')]
    public function testProfilesProduceSpacedNonRepeatingPlans(string $biome, int $minimumArchetypes): void
    {
        $planner = new OverworldVegetationPlanner(new SeededNoise(0));
        $plans = $planner->plansAffecting(
            -128,
            127,
            -128,
            127,
            static fn(int $x, int $z): string => $biome,
            static fn(int $x, int $z): int => 70,
        );
        $plans = array_values(array_filter(
            $plans,
            static fn($plan): bool => $plan->x >= -128 && $plan->x <= 127
                && $plan->z >= -128 && $plan->z <= 127,
        ));
        $profile = VegetationProfileCatalog::forBiome($biome);
        self::assertNotNull($profile);
        self::assertGreaterThan(12, count($plans), "$biome must not become an empty woodland.");

        $archetypes = [];
        $signatures = [];
        $occupiedCells = [];
        $minimumDistanceSquared = PHP_INT_MAX;
        foreach ($plans as $index => $plan) {
            self::assertSame($profile->name, $plan->profile);
            $archetypes[$plan->archetype->value] = true;
            $signatures[$plan->archetype->value . ':' . $plan->height . ':' . $plan->rotation . ':' . $plan->shapeSeed] = true;
            $occupiedCells[intdiv($plan->x + 128, 32) . ':' . intdiv($plan->z + 128, 32)] = true;
            foreach (array_slice($plans, $index + 1) as $other) {
                $distanceX = $plan->x - $other->x;
                $distanceZ = $plan->z - $other->z;
                $minimumDistanceSquared = min($minimumDistanceSquared, $distanceX * $distanceX + $distanceZ * $distanceZ);
            }
        }

        self::assertGreaterThanOrEqual($minimumArchetypes, count($archetypes));
        self::assertGreaterThanOrEqual(0.99, count($signatures) / count($plans));
        self::assertGreaterThanOrEqual(
            $profile->minimumSpacing ** 2,
            $minimumDistanceSquared,
            "$biome contains a repeated close-packed tree pair.",
        );
        self::assertLessThan(64, count($occupiedCells), "$biome must retain travel-readable clearings.");
    }

    /** @return iterable<string, array{string, int}> */
    public static function representativeBiomes(): iterable
    {
        yield 'forest' => ['minecraft:forest', 5];
        yield 'flower forest' => ['minecraft:flower_forest', 5];
        yield 'birch forest' => ['minecraft:birch_forest', 3];
        yield 'dark forest' => ['minecraft:roofed_forest', 2];
        yield 'pale garden' => ['minecraft:pale_garden', 2];
        yield 'dappled forest' => ['minecraft:dappled_forest', 4];
        yield 'taiga' => ['minecraft:taiga', 3];
        yield 'old growth taiga' => ['minecraft:mega_taiga', 2];
        yield 'savanna' => ['minecraft:savanna', 2];
        yield 'jungle' => ['minecraft:jungle', 2];
        yield 'bamboo jungle' => ['minecraft:bamboo_jungle', 2];
        yield 'mangrove' => ['minecraft:mangrove_swamp', 2];
        yield 'swamp' => ['minecraft:swampland', 2];
        yield 'cherry grove' => ['minecraft:cherry_grove', 2];
        yield 'wooded mountains' => ['minecraft:extreme_hills_plus_trees', 3];
        yield 'wooded badlands' => ['minecraft:mesa_plateau_stone', 2];
        yield 'meadow' => ['minecraft:meadow', 2];
        yield 'plains' => ['minecraft:plains', 3];
    }

    public function testPlanningIsDeterministicAndIndependentOfQueryPartitioning(): void
    {
        $wholePlanner = new OverworldVegetationPlanner(new SeededNoise(984_123));
        $whole = $wholePlanner->plansAffecting(
            -128,
            127,
            -128,
            127,
            static fn(int $x, int $z): string => 'minecraft:forest',
            static fn(int $x, int $z): int => 72,
        );
        $partitionedPlanner = new OverworldVegetationPlanner(new SeededNoise(984_123));
        $partitioned = array_merge(
            $partitionedPlanner->plansAffecting(-128, -1, -128, 127, static fn(int $x, int $z): string => 'minecraft:forest', static fn(int $x, int $z): int => 72),
            $partitionedPlanner->plansAffecting(0, 127, -128, 127, static fn(int $x, int $z): string => 'minecraft:forest', static fn(int $x, int $z): int => 72),
        );

        self::assertSame(self::signatures($whole, -128, 127), self::signatures($partitioned, -128, 127));
    }

    public function testEcologyRejectsSubmergedAndCliffCandidates(): void
    {
        $biomeAt = static fn(int $x, int $z): string => 'minecraft:forest';

        $submerged = (new OverworldVegetationPlanner(new SeededNoise(818_019)))->plansAffecting(
            0,
            31,
            0,
            31,
            $biomeAt,
            static fn(int $x, int $z): int => 55,
        );
        self::assertSame([], $submerged);

        $cliff = (new OverworldVegetationPlanner(new SeededNoise(818_019)))->plansAffecting(
            0,
            31,
            0,
            31,
            $biomeAt,
            static fn(int $x, int $z): int => 100 + $x * 4,
        );
        self::assertSame([], $cliff);
    }

    public function testCrossChunkQueryCannotEmitAnOrphanPlanOwnedBySubmergedTerrain(): void
    {
        $planner = new OverworldVegetationPlanner(new SeededNoise(818_019));
        $plans = $planner->plansAffecting(
            0,
            15,
            0,
            15,
            static fn(int $x, int $z): string => 'minecraft:forest',
            static fn(int $x, int $z): int => $x >= 16 ? 55 : 72,
        );

        self::assertNotSame([], $plans);
        foreach ($plans as $plan) {
            self::assertLessThan(16, $plan->x, 'A neighboring underwater root must not project orphan blocks into this chunk.');
        }
    }

    public function testEcologyUsesOneDensityDecisionWithoutEmptyingForestCores(): void
    {
        $planner = new OverworldVegetationPlanner(new SeededNoise(41_983));
        $plans = $planner->plansAffecting(
            -128,
            127,
            -128,
            127,
            static fn(int $x, int $z): string => 'minecraft:forest',
            static fn(int $x, int $z): int => 72,
        );
        $inside = array_filter(
            $plans,
            static fn($plan): bool => $plan->x >= -128 && $plan->x <= 127
                && $plan->z >= -128 && $plan->z <= 127,
        );

        self::assertGreaterThanOrEqual(150, count($inside));
        self::assertLessThanOrEqual(2_000, count($inside));
    }

    /** @param list<\Bedriox\Server\World\Generation\Vegetation\VegetationTreePlan> $plans
     * @return list<string>
     */
    private static function signatures(array $plans, int $minimumX, int $maximumX): array
    {
        $signatures = [];
        foreach ($plans as $plan) {
            if ($plan->x < $minimumX || $plan->x > $maximumX || $plan->z < -128 || $plan->z > 127) {
                continue;
            }
            $signatures[$plan->x . ':' . $plan->z] = implode(':', [
                $plan->archetype->value,
                $plan->height,
                $plan->rotation,
                $plan->shapeSeed,
            ]);
        }
        ksort($signatures);

        return array_values($signatures);
    }
}
