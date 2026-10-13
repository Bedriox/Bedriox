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

namespace Bedriox\Server\Tests\World\Generation\Structure\End;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\EndWorldGenerator;
use Bedriox\Server\World\Generation\Structure\End\EndInfrastructureCatalog;
use Bedriox\Server\World\Generation\Structure\End\EndStructureKind;
use Bedriox\Server\World\Generation\Structure\End\EndStructurePlan;
use Bedriox\Server\World\Generation\Structure\End\EndStructurePlanner;
use PHPUnit\Framework\TestCase;

final class EndStructurePlannerTest extends TestCase
{
    public function testCitiesAndShipsHaveDeterministicNegativeCoordinatePlans(): void
    {
        $planner = new EndStructurePlanner(42);
        foreach (EndStructureKind::cases() as $kind) {
            $plan = $planner->nearest($kind, -4_000, -3_000, 16);
            self::assertNotNull($plan, $kind->value);
            self::assertEquals($plan, (new EndStructurePlanner(42))->nearest($kind, -4_000, -3_000, 16));
            $chunk = new ChunkPosition(self::floorDiv($plan->centerX, 16), self::floorDiv($plan->centerZ, 16));
            self::assertContains($plan->id, array_map(
                static fn(EndStructurePlan $candidate): string => $candidate->id,
                $planner->intersecting($chunk),
            ));
        }
    }

    public function testInfrastructureCatalogCoversArenaAndSpawnLandmarks(): void
    {
        $landmarks = EndInfrastructureCatalog::landmarks();
        self::assertCount(12, $landmarks);
        self::assertCount(12, array_unique(array_map(static fn($landmark): string => $landmark->id, $landmarks)));
        self::assertSame(10, count(array_filter($landmarks, static fn($landmark): bool => $landmark->kind === 'obsidian_pillar')));
        self::assertContains('end_exit_portal', array_map(static fn($landmark): string => $landmark->kind, $landmarks));
        self::assertContains('end_spawn_platform', array_map(static fn($landmark): string => $landmark->kind, $landmarks));
        self::assertContains('caged', array_map(static fn($landmark): string => $landmark->variant, $landmarks));
        self::assertContains('uncaged', array_map(static fn($landmark): string => $landmark->variant, $landmarks));
    }

    public function testAllFourCityLayoutsAndBothOrientationsAreReachable(): void
    {
        $plans = (new EndStructurePlanner(42))->candidates(EndStructureKind::City, 0, 0, 16);
        $variants = array_values(array_unique(array_map(
            static fn(EndStructurePlan $plan): int => $plan->variant,
            $plans,
        )));
        sort($variants, SORT_NUMERIC);
        $orientations = array_values(array_unique(array_map(
            static fn(EndStructurePlan $plan): int => $plan->alongX ? 1 : 0,
            $plans,
        )));
        sort($orientations, SORT_NUMERIC);

        self::assertSame([0, 1, 2, 3], $variants);
        self::assertSame([0, 1], $orientations);
    }

    public function testQualifiedShipLocationOwnsPersistentLoot(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new EndWorldGenerator(42, $states);
        $plan = $generator->locateStructure(EndStructureKind::CityWithShip, 0, 0, 16);
        self::assertNotNull($plan);
        $shipX = $plan->centerX + ($plan->alongX ? 34 : 62);
        $shipZ = $plan->centerZ + ($plan->alongX ? 62 : 34);
        $chestX = $shipX + ($plan->alongX ? -3 : 0);
        $chestZ = $shipZ + ($plan->alongX ? 0 : -3);
        $chunk = $generator->generate(new ChunkPosition(self::floorDiv($chestX, 16), self::floorDiv($chestZ, 16)));

        self::assertCount(1, $chunk->blockEntities());
    }

    public function testQualifiedLocationsExposeRenderedStandingSpace(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new EndWorldGenerator(42, $states);
        $location = $generator->locateStructureLocation(EndStructureKind::CityWithShip, 0, 0, 16);
        self::assertNotNull($location);
        self::assertNotNull($location->shipTeleport);
        self::assertCount(3, $location->markers());
        foreach ([$location->cityTeleport, $location->shipTeleport] as $position) {
            $chunk = $generator->generate(new ChunkPosition(self::floorDiv($position->x, 16), self::floorDiv($position->z, 16)));
            $localX = (($position->x % 16) + 16) % 16;
            $localZ = (($position->z % 16) + 16) % 16;
            self::assertSame('minecraft:air', $states->state($chunk->blockStateAt($localX, $position->y, $localZ))->identifier());
            self::assertNotSame('minecraft:air', $states->state($chunk->blockStateAt($localX, $position->y - 1, $localZ))->identifier());
        }
    }

    public function testSearchRadiusIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EndStructurePlanner(42))->nearest(EndStructureKind::City, 0, 0, -1);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
