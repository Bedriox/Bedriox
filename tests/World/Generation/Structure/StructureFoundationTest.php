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

namespace Bedriox\Server\Tests\World\Generation\Structure;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\Structure\IgnoreAirStructureProcessor;
use Bedriox\Server\World\Generation\Structure\PlacedStructureBlock;
use Bedriox\Server\World\Generation\Structure\PlacedStructureMarker;
use Bedriox\Server\World\Generation\Structure\StructureCoordinate;
use Bedriox\Server\World\Generation\Structure\StructureDirection;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;
use Bedriox\Server\World\Generation\Structure\StructurePiece;
use Bedriox\Server\World\Generation\Structure\StructurePieceGraph;
use Bedriox\Server\World\Generation\Structure\StructurePlacement;
use Bedriox\Server\World\Generation\Structure\StructurePlan;
use Bedriox\Server\World\Generation\Structure\StructurePlanCache;
use Bedriox\Server\World\Generation\Structure\StructurePlanner;
use Bedriox\Server\World\Generation\Structure\StructurePool;
use Bedriox\Server\World\Generation\Structure\StructurePoolEntry;
use Bedriox\Server\World\Generation\Structure\StructureSet;
use Bedriox\Server\World\Generation\Structure\StructureStartPlanner;
use Bedriox\Server\World\Generation\Structure\StructureTemplate;
use PHPUnit\Framework\TestCase;

final class StructureFoundationTest extends TestCase
{
    public function testStartCandidatesAreStableAcrossOrderAndNegativeRegions(): void
    {
        $planner = new StructureStartPlanner();
        $set = new StructureSet('village', 34, 8, 10_387_312, new class implements StructurePlanner {
            public function plan(int $worldSeed, int $startChunkX, int $startChunkZ): ?StructurePlan
            {
                return null;
            }
        });

        $negative = $planner->candidateForChunk(42, $set, -1, -35);
        self::assertSame(-1, $negative->regionX);
        self::assertSame(-2, $negative->regionZ);
        self::assertEquals($negative, $planner->candidateForRegion(42, $set, -1, -2));
        self::assertNotEquals($negative, $planner->candidateForRegion(43, $set, -1, -2));
    }

    public function testPoolSelectionIsDeterministic(): void
    {
        $first = new StructureTemplate(1, 1, 1, []);
        $second = new StructureTemplate(1, 1, 1, []);
        $pool = new StructurePool([
            new StructurePoolEntry($first),
            new StructurePoolEntry($second, 3),
        ]);

        self::assertSame($pool->choose(99, 'room:4'), $pool->choose(99, 'room:4'));
    }

    public function testPlanReplaysOnlyBlocksIntersectingEachChunk(): void
    {
        $palette = self::palette();
        $placement = new StructurePlacement(2, 1, 1, [
            new PlacedStructureBlock(new StructureCoordinate(15, 64, 0), CanonicalBlockState::from('minecraft:stone')),
            new PlacedStructureBlock(new StructureCoordinate(16, 64, 0), CanonicalBlockState::from('minecraft:oak_planks')),
            new PlacedStructureBlock(new StructureCoordinate(16, 65, 0), CanonicalBlockState::from('minecraft:air')),
        ], [], [
            new PlacedStructureMarker(
                'resident',
                StructureMarkerType::EntitySpawn,
                'minecraft:villager_v2',
                new StructureCoordinate(16, 64, 0),
                StructureDirection::South,
                ['profession' => 'farmer'],
            ),
        ]);
        $piece = new StructurePiece('bridge', $placement, [new IgnoreAirStructureProcessor()]);
        $plan = new StructurePlan(
            'cross_chunk',
            new StructureCoordinate(15, 64, 0),
            new StructurePieceGraph('bridge', [$piece]),
        );
        $west = new MutableChunkBuilder(new ChunkPosition(0, 0), $palette);
        $east = new MutableChunkBuilder(new ChunkPosition(1, 0), $palette);
        $plan->replay($west, $palette);
        $plan->replay($east, $palette);

        self::assertSame($palette->state('minecraft:stone')->value, $west->state(15, 64, 0)->value);
        self::assertSame($palette->state('minecraft:oak_planks')->value, $east->state(0, 64, 0)->value);
        self::assertSame($palette->state('minecraft:air')->value, $east->state(0, 65, 0)->value);
        self::assertCount(0, $plan->markersInChunk(new ChunkPosition(0, 0)));
        self::assertSame('resident', $plan->markersInChunk(new ChunkPosition(1, 0))[0]->key);
    }

    public function testPlanCacheStoresNullAndEvictsLeastRecentlyUsed(): void
    {
        $cache = new StructurePlanCache(2);
        $calls = 0;
        self::assertNull($cache->getOrCompute('missing', static function () use (&$calls): ?StructurePlan {
            ++$calls;
            return null;
        }));
        self::assertNull($cache->getOrCompute('missing', static function () use (&$calls): ?StructurePlan {
            ++$calls;
            return null;
        }));
        $cache->getOrCompute('second', static fn(): ?StructurePlan => null);
        $cache->getOrCompute('third', static fn(): ?StructurePlan => null);

        self::assertSame(1, $calls);
        self::assertSame(2, $cache->count());
    }

    private static function palette(): GenerationBlockPalette
    {
        return GenerationBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
    }
}
