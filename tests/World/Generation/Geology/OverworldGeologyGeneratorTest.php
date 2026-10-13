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

namespace Bedriox\Server\Tests\World\Generation\Geology;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\Geology\OverworldGeologyGenerator;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use PHPUnit\Framework\TestCase;

final class OverworldGeologyGeneratorTest extends TestCase
{
    public function testCapturedSeedZeroChunkOwnedGeometryRemainsDeterministic(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $position = new ChunkPosition(0, 0);
        $builder = new MutableChunkBuilder($position, $palette);
        $stone = $palette->state('minecraft:stone');
        for ($y = Chunk::MIN_Y; $y <= Chunk::MAX_Y; ++$y) {
            for ($z = 0; $z < 16; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    $builder->set($x, $y, $z, $stone);
                }
            }
        }

        (new OverworldGeologyGenerator(0, $palette))->populate($builder, $position);

        $placements = [];
        $edgePlacements = 0;
        for ($y = Chunk::MIN_Y; $y <= Chunk::MAX_Y; ++$y) {
            for ($z = 0; $z < 16; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    $state = $builder->state($x, $y, $z);
                    if ($state->value === $stone->value) {
                        continue;
                    }
                    $placements[] = $x . ',' . $y . ',' . $z . '=' . $states->state($state)->identifier();
                    $edgePlacements += (int) ($x === 0 || $x === 15 || $z === 0 || $z === 15);
                }
            }
        }

        self::assertSame(2_104, count($placements));
        self::assertSame('a750b070e82ec87a7208b6bd253ea2c57cf8dac8b63ff5a5006625a3cbbd84d0', hash('sha256', implode("\n", $placements)));
        self::assertSame(414, $edgePlacements);
    }

    public function testAirExposureUsesKnownTargetVoxelsAndTreatsUnknownNeighboursConservatively(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($states);
        $builder = new MutableChunkBuilder(new ChunkPosition(0, 0), $palette);
        $stone = $palette->state('minecraft:stone');
        $builder->set(8, 0, 8, $stone);
        $builder->set(7, 0, 8, $stone);
        $builder->set(9, 0, 8, $stone);
        $builder->set(8, -1, 8, $stone);
        $builder->set(8, 1, 8, $stone);
        $builder->set(8, 0, 7, $stone);
        $builder->set(8, 0, 9, $stone);

        $method = new \ReflectionMethod(OverworldGeologyGenerator::class, 'touchesAir');
        $generator = new OverworldGeologyGenerator(0, $palette);
        self::assertFalse($method->invoke($generator, $builder, 8, 0, 8));

        $builder->set(8, 0, 9, $palette->state('minecraft:air'));
        self::assertTrue($method->invoke($generator, $builder, 8, 0, 8));

        $builder->set(0, 0, 0, $stone);
        $builder->set(1, 0, 0, $stone);
        $builder->set(0, -1, 0, $stone);
        $builder->set(0, 1, 0, $stone);
        $builder->set(0, 0, 1, $stone);
        self::assertFalse($method->invoke($generator, $builder, 0, 0, 0));
    }
}
