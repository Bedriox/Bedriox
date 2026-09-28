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

namespace Bedriox\Server\Tests\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\FlatWorldGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FlatWorldGeneratorTest extends TestCase
{
    public function testEveryCoordinateHasTheExpectedFlatLayer(): void
    {
        [$generator, $palette] = $this->generator();
        foreach ([new ChunkPosition(0, 0), new ChunkPosition(-17, 31), new ChunkPosition(0x7fffffff, -0x80000000)] as $position) {
            $chunk = $generator->generate($position);
            self::assertSame($position, $chunk->position);
            self::assertCount(1, $chunk->populatedSections());
            self::assertSame('minecraft:plains', $chunk->biome()->identifier);
            for ($x = 0; $x < 16; ++$x) {
                for ($z = 0; $z < 16; ++$z) {
                    self::assertSame($palette->air->value, $chunk->blockStateAt($x, Chunk::MIN_Y, $z)->value);
                    self::assertSame($palette->air->value, $chunk->blockStateAt($x, 59, $z)->value);
                    self::assertSame($palette->bedrock->value, $chunk->blockStateAt($x, 60, $z)->value);
                    self::assertSame($palette->dirt->value, $chunk->blockStateAt($x, 61, $z)->value);
                    self::assertSame($palette->dirt->value, $chunk->blockStateAt($x, 62, $z)->value);
                    self::assertSame($palette->grassBlock->value, $chunk->blockStateAt($x, 63, $z)->value);
                    self::assertSame($palette->air->value, $chunk->blockStateAt($x, 64, $z)->value);
                    self::assertSame($palette->air->value, $chunk->blockStateAt($x, 65, $z)->value);
                    self::assertSame($palette->air->value, $chunk->blockStateAt($x, Chunk::MAX_Y, $z)->value);
                }
            }
        }
    }

    public function testGenerationIsDeterministicAndDefaultSpawnIsSafe(): void
    {
        [$generator] = $this->generator();
        $position = new ChunkPosition(-2, -3);
        $first = $generator->generate($position);
        $second = $generator->generate(new ChunkPosition(-2, -3));

        self::assertEquals($first, $second);
        self::assertSame('flat', $generator->name());
        self::assertSame([0, 64, 0], [
            $generator->defaultSpawn()->x,
            $generator->defaultSpawn()->y,
            $generator->defaultSpawn()->z,
        ]);
    }

    public function testChunkRejectsCoordinatesOutsideItsLocalAndVerticalBounds(): void
    {
        [$generator] = $this->generator();
        $chunk = $generator->generate(new ChunkPosition(0, 0));

        foreach ([[-1, 0, 0], [16, 0, 0], [0, -65, 0], [0, 320, 0], [0, 0, 16]] as [$x, $y, $z]) {
            try {
                $chunk->blockStateAt($x, $y, $z);
                self::fail('Out-of-range chunk coordinate was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return array{FlatWorldGenerator, FixedFlatBlockPalette} */
    private function generator(): array
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);

        return [new FlatWorldGenerator($palette), $palette];
    }
}
