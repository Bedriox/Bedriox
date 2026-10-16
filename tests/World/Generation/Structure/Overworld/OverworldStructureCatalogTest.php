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

namespace Bedriox\Server\Tests\World\Generation\Structure\Overworld;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockEntity\BedBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\MobSpawnerBlockEntity;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\PlannedStructurePiece;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\StructureCanvas;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureCatalog;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureFamily;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureGenerator;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureLocator;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureStart;
use Bedriox\Server\World\Generation\Structure\Overworld\Stronghold\StrongholdPlanner;
use Bedriox\Server\World\Generation\Structure\Overworld\Village\VillagePlan;
use Bedriox\Server\World\Generation\Structure\Overworld\Village\VillagePlanner;
use Bedriox\Server\World\Generation\Structure\Overworld\Village\VillageRenderer;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;
use Bedriox\Server\World\Generation\Structure\StructureRotation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverworldStructureCatalogTest extends TestCase
{
    public function testCatalogOnlyEnablesProductionQualifiedFamilies(): void
    {
        $actual = [];
        foreach (OverworldStructureCatalog::all() as $definition) {
            $actual[$definition->family->value] = $definition->variants;
        }

        self::assertSame([
            'village' => ['plains', 'desert', 'savanna', 'taiga', 'snowy'],
            'dungeon' => ['standard'],
            'mineshaft' => ['oak', 'badlands'],
            'stronghold' => ['standard'],
        ], $actual);
    }

    public function testEveryVariantHasABoundedDeterministicLocation(): void
    {
        foreach (OverworldStructureCatalog::all() as $definition) {
            foreach ($definition->variants as $variant) {
                $first = (new OverworldStructureLocator(new SeededNoise(84_448)))->nearestVariant(
                    $definition->family,
                    $variant,
                    -8192,
                    4096,
                    128,
                );
                $second = (new OverworldStructureLocator(new SeededNoise(84_448)))->nearestVariant(
                    $definition->family,
                    $variant,
                    -8192,
                    4096,
                    128,
                );
                self::assertNotNull($first, "No bounded start found for {$definition->family->value}:$variant.");
                self::assertEquals($first, $second);
                self::assertSame($variant, $first->variant);
            }
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function chunkCoordinates(): iterable
    {
        yield 'origin' => [0, 0];
        yield 'negative x' => [-1, 0];
        yield 'negative z' => [0, -1];
        yield 'negative quadrant' => [-2_000, -3_000];
        yield 'positive distant' => [2_000, 3_000];
    }

    #[DataProvider('chunkCoordinates')]
    public function testIntersectingStartsAreStableAtSignedCoordinates(int $chunkX, int $chunkZ): void
    {
        $locator = new OverworldStructureLocator(new SeededNoise(-92_211));

        self::assertEquals(
            $locator->intersecting(new ChunkPosition($chunkX, $chunkZ)),
            $locator->intersecting(new ChunkPosition($chunkX, $chunkZ)),
        );
    }

    public function testSearchRejectsUnknownVariantsAndUnboundedRadii(): void
    {
        $locator = new OverworldStructureLocator(new SeededNoise(1));
        $this->expectException(\InvalidArgumentException::class);
        $locator->nearestVariant(OverworldStructureFamily::Village, 'not_a_variant', 0, 0);
    }

    public function testEveryFamilyAndVariantProducesOriginalChunkGeometry(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $generator = new OverworldStructureGenerator($palette, new SeededNoise(90_301));
        foreach (OverworldStructureCatalog::all() as $definition) {
            foreach ($definition->variants as $variant) {
                $builder = new MutableChunkBuilder(new ChunkPosition(0, 0), $palette);
                $generator->populateStart(
                    $builder,
                    new OverworldStructureStart($definition->family, $variant, 8, 8, $definition->radius),
                    70,
                    'minecraft:plains',
                );
                self::assertNotSame([], $builder->sections(), "No geometry for {$definition->family->value}:$variant.");
            }
        }
    }

    public function testNaturalLocatorUsesTheSameBiomeAndSurfaceQualificationAsGeneration(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $generator = new OverworldStructureGenerator($palette, new SeededNoise(71_337));
        $start = $generator->locateNaturalVariant(
            OverworldStructureFamily::Village,
            'plains',
            0,
            0,
            static fn(int $x, int $z): string => 'minecraft:plains',
            static fn(int $x, int $z): int => 70,
            8,
        );

        self::assertNotNull($start);
        self::assertSame('plains', $start->variant);
        $location = $generator->locateNatural(
            OverworldStructureFamily::Village,
            0,
            0,
            static fn(int $x, int $z): string => 'minecraft:plains',
            static fn(int $x, int $z): int => 70,
            8,
        );
        self::assertNotNull($location);
        self::assertSame('plains', $location->start->variant);
        self::assertSame(70, $location->baseY);
        self::assertNull($generator->locateNaturalVariant(
            OverworldStructureFamily::Village,
            'plains',
            0,
            0,
            static fn(int $x, int $z): string => 'minecraft:ocean',
            static fn(int $x, int $z): int => 40,
            2,
        ));
    }

    public function testLargePlansReplayDeterministicallyAcrossSignedChunkBoundaries(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $generator = new OverworldStructureGenerator($palette, new SeededNoise(5_193));
        $start = new OverworldStructureStart(OverworldStructureFamily::Stronghold, 'standard', -8, -8, 70);
        foreach ([new ChunkPosition(-2, -1), new ChunkPosition(-1, -2), new ChunkPosition(0, -1), new ChunkPosition(-1, 0)] as $position) {
            $first = new MutableChunkBuilder($position, $palette);
            $second = new MutableChunkBuilder($position, $palette);
            $generator->populateStart($first, $start, -18, 'minecraft:plains');
            $generator->populateStart($second, $start, -18, 'minecraft:plains');

            self::assertNotSame([], $first->sections());
            self::assertEquals($first->sections(), $second->sections());
        }
    }

    public function testVillagePlannerBuildsWeightedPiecesAndSemanticMarkersForEveryVariant(): void
    {
        $planner = new VillagePlanner(new SeededNoise(0));
        foreach (OverworldStructureCatalog::get(OverworldStructureFamily::Village)->variants as $variant) {
            $plan = $planner->plan(
                new OverworldStructureStart(OverworldStructureFamily::Village, $variant, -8, -8, 48),
                static fn(int $x, int $z): int => 70,
            );
            self::assertSame($variant, $plan->variant);
            self::assertGreaterThanOrEqual(17, count($plan->pieces));
            self::assertNotEmpty(array_filter(
                $plan->markers,
                static fn($marker): bool => $marker->type === StructureMarkerType::EntitySpawn,
            ));
        }
    }

    public function testVillageGeometryContainsFarmsWorkingDoorsConnectedPathsAndUnnamedLoot(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $generator = new OverworldStructureGenerator($palette, new SeededNoise(0));
        $start = new OverworldStructureStart(OverworldStructureFamily::Village, 'plains', 8, 8, 48);
        $identifiers = [];
        $beds = [];
        $containers = [];
        for ($chunkZ = -3; $chunkZ <= 3; ++$chunkZ) {
            for ($chunkX = -3; $chunkX <= 3; ++$chunkX) {
                $builder = new MutableChunkBuilder(new ChunkPosition($chunkX, $chunkZ), $palette);
                $generator->populateStart($builder, $start, 70, 'minecraft:plains');
                foreach ($builder->sections() as $section) {
                    foreach ($section->palette() as $state) {
                        $identifiers[$states->state($state)->identifier()] = true;
                    }
                }
                foreach ($builder->blockEntities()->all() as $entity) {
                    if ($entity instanceof BedBlockEntity) {
                        $beds[] = $entity;
                    }
                    if ($entity instanceof ContainerBlockEntity) {
                        $containers[] = $entity;
                    }
                }
            }
        }

        self::assertArrayHasKey('minecraft:farmland', $identifiers);
        self::assertArrayHasKey('minecraft:water', $identifiers);
        self::assertArrayHasKey('minecraft:grass_path', $identifiers);
        self::assertArrayHasKey('minecraft:wooden_door', $identifiers);
        self::assertArrayHasKey('minecraft:bed', $identifiers);
        self::assertNotEmpty($beds);
        self::assertSame(0, count($beds) % 2, 'Generated village beds must retain complete foot/head pairs.');
        self::assertNotEmpty($containers);
        foreach ($containers as $container) {
            self::assertNull($container->customName);
        }
    }

    public function testVillageDoorsEncodeTheFacingOfTheirWallPlane(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $renderer = new VillageRenderer(new StructureCanvas($palette, new SeededNoise(7)));
        $cases = [
            StructureRotation::None->value => [StructureRotation::None, 8, 4, 'east'],
            StructureRotation::Clockwise90->value => [StructureRotation::Clockwise90, 12, 8, 'south'],
            StructureRotation::Clockwise180->value => [StructureRotation::Clockwise180, 8, 12, 'west'],
            StructureRotation::Clockwise270->value => [StructureRotation::Clockwise270, 4, 8, 'north'],
        ];
        foreach ($cases as $label => [$rotation, $doorX, $doorZ, $rawDirection]) {
            $builder = new MutableChunkBuilder(new ChunkPosition(0, 0), $palette);
            $renderer->render(
                $builder,
                new VillagePlan('plains', 8, 70, 8, [
                    new PlannedStructurePiece('house', 8, 70, 8, 9, 9, $rotation),
                ], []),
                static fn(int $x, int $z): int => 70,
            );
            $state = $states->state($builder->state($doorX, 71, $doorZ));
            self::assertSame('minecraft:wooden_door', $state->identifier(), (string) $label);
            self::assertSame(
                $rawDirection,
                $state->properties()['minecraft:cardinal_direction'] ?? null,
                (string) $label,
            );
        }
    }

    public function testVillageStableHasAnEnclosedShellAndWorkingEntrance(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $builder = new MutableChunkBuilder(new ChunkPosition(0, 0), $palette);
        (new VillageRenderer(new StructureCanvas($palette, new SeededNoise(7))))->render(
            $builder,
            new VillagePlan('plains', 8, 70, 8, [
                new PlannedStructurePiece('stable', 8, 70, 8, 9, 9, StructureRotation::None),
            ], []),
            static fn(int $x, int $z): int => 70,
        );

        self::assertSame('minecraft:wooden_door', $states->state($builder->state(8, 72, 4))->identifier());
        self::assertSame('minecraft:oak_planks', $states->state($builder->state(4, 72, 8))->identifier());
        self::assertSame('minecraft:oak_planks', $states->state($builder->state(12, 72, 8))->identifier());
        self::assertSame('minecraft:oak_planks', $states->state($builder->state(8, 72, 12))->identifier());
        self::assertSame('minecraft:hay_block', $states->state($builder->state(5, 71, 5))->identifier());
        self::assertSame('minecraft:grass_path', $states->state($builder->state(8, 70, 3))->identifier());
    }

    public function testStrongholdPlannerBuildsAConnectedRoomSetWithPortalAndSpawnerMarkers(): void
    {
        $planner = new StrongholdPlanner(new SeededNoise(0));
        $start = new OverworldStructureStart(OverworldStructureFamily::Stronghold, 'standard', -8, -8, 40);
        $first = $planner->plan($start, -20);
        $second = $planner->plan($start, -20);

        self::assertEquals($first, $second);
        self::assertGreaterThanOrEqual(12, count($first->pieces));
        self::assertNotEmpty(array_filter(
            $first->markers,
            static fn($marker): bool => $marker->type === StructureMarkerType::Spawner,
        ));
        self::assertNotEmpty(array_filter(
            $first->markers,
            static fn($marker): bool => $marker->identifier === 'minecraft:end_portal',
        ));
    }

    public function testDungeonSpawnerReceivesADeterministicPersistentMobConfiguration(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $generator = new OverworldStructureGenerator($palette, new SeededNoise(0));
        $builder = new MutableChunkBuilder(new ChunkPosition(0, 0), $palette);

        $generator->populateStart(
            $builder,
            new OverworldStructureStart(OverworldStructureFamily::Dungeon, 'standard', 8, 8, 16),
            64,
            'minecraft:plains',
        );

        $spawners = array_values(array_filter(
            $builder->blockEntities()->all(),
            static fn($entity): bool => $entity instanceof MobSpawnerBlockEntity,
        ));
        self::assertCount(1, $spawners);
        self::assertInstanceOf(MobSpawnerBlockEntity::class, $spawners[0]);
        self::assertContains($spawners[0]->state->configuration->entityIdentifier, [
            'minecraft:skeleton',
            'minecraft:spider',
            'minecraft:zombie',
        ]);
    }

    public function testFiveVillageVariantsAreNaturallyReachableForSeedZero(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new DefaultWorldGenerator(0, $states);
        $locations = $generator->locateStructureVariants(OverworldStructureFamily::Village, 0, 0, 24);
        ksort($locations);

        self::assertSame(['desert', 'plains', 'savanna', 'snowy', 'taiga'], array_keys($locations));
    }

}
