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

namespace Bedriox\Server\Tests\World\Generation\Structure\Nether;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\Structure\Nether\NetherStructureKind;
use Bedriox\Server\World\Generation\Structure\Nether\NetherStructurePlan;
use Bedriox\Server\World\Generation\Structure\Nether\NetherStructurePlanner;
use Bedriox\Server\World\Generation\Structure\Nether\NetherStructureRenderer;
use Bedriox\Server\World\NetherWorldGenerator;
use PHPUnit\Framework\TestCase;

final class NetherStructurePlannerTest extends TestCase
{
    public function testEveryFamilyHasABoundedDeterministicLocation(): void
    {
        $first = new NetherStructurePlanner(42);
        $same = new NetherStructurePlanner(42);
        foreach (NetherStructureKind::cases() as $kind) {
            $plan = $first->nearest($kind, -2_000, 1_500, 16);
            self::assertNotNull($plan, $kind->value);
            self::assertEquals($plan, $same->nearest($kind, -2_000, 1_500, 16));
            $chunk = new ChunkPosition(self::floorDiv($plan->centerX, 16), self::floorDiv($plan->centerZ, 16));
            self::assertContains($plan->id, array_map(
                static fn(NetherStructurePlan $candidate): string => $candidate->id,
                $first->intersecting($chunk),
            ));
            if ($kind === NetherStructureKind::Fortress || $kind->isBastion()) {
                self::assertNotEmpty($plan->markers(), $kind->value);
                self::assertCount(count($plan->markers()), array_unique(array_map(
                    static fn($marker): string => $marker->key,
                    $plan->markers(),
                )));
            }
        }
    }

    public function testOriginalLayoutsClipCleanlyAtNegativeChunkBoundariesAndOwnLoot(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $renderer = new NetherStructureRenderer($palette);
        $plan = new NetherStructurePlan('fixture', NetherStructureKind::BastionTreasure, -8, 64, -8, 0, 42);
        $northWest = new MutableChunkBuilder(new ChunkPosition(-1, -1), $palette);
        $southEast = new MutableChunkBuilder(new ChunkPosition(0, 0), $palette);

        $renderer->render($northWest, $plan);
        $renderer->render($southEast, $plan);

        self::assertNotEmpty($northWest->sections());
        self::assertNotEmpty($southEast->sections());
        self::assertCount(1, $northWest->blockEntities());
        self::assertCount(0, $southEast->blockEntities());
    }

    public function testSearchRadiusIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NetherStructurePlanner(42))->nearest(NetherStructureKind::Fortress, 0, 0, 65);
    }

    public function testQualifiedLocationsPointToGeneratedStandingSpace(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new NetherWorldGenerator(0, $states);
        foreach (NetherStructureKind::cases() as $kind) {
            $location = $generator->locateStructureLocation($kind, 0, 0, 16);
            self::assertNotNull($location, $kind->value);
            $position = $location->safeTeleport;
            $chunk = $generator->generate(new ChunkPosition(self::floorDiv($position->x, 16), self::floorDiv($position->z, 16)));
            $localX = (($position->x % 16) + 16) % 16;
            $localZ = (($position->z % 16) + 16) % 16;
            self::assertSame('minecraft:air', $states->state($chunk->blockStateAt($localX, $position->y, $localZ))->identifier(), $kind->value);
            self::assertSame('minecraft:air', $states->state($chunk->blockStateAt($localX, $position->y + 1, $localZ))->identifier(), $kind->value);
        }
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
