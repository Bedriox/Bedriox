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

namespace Bedriox\Server\Tests\World\Generation;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use PHPUnit\Framework\TestCase;

final class OverworldVerticalMaterialTest extends TestCase
{
    public function testBedrockFloorAndDeepslateTransitionFollowTheirVerticalGradients(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $chunk = (new DefaultWorldGenerator(0, $states))->generate(new ChunkPosition(0, 0));

        self::assertSame(256, self::countBlocks($chunk, $states, Chunk::MIN_Y, 'minecraft:bedrock'));
        self::assertSame(256, self::countBlocks($chunk, $states, Chunk::MIN_Y + 1, 'minecraft:bedrock'));
        $upperBedrock = [
            self::countBlocks($chunk, $states, Chunk::MIN_Y + 2, 'minecraft:bedrock'),
            self::countBlocks($chunk, $states, Chunk::MIN_Y + 3, 'minecraft:bedrock'),
            self::countBlocks($chunk, $states, Chunk::MIN_Y + 4, 'minecraft:bedrock'),
        ];
        self::assertGreaterThan($upperBedrock[1], $upperBedrock[0]);
        self::assertGreaterThan($upperBedrock[2], $upperBedrock[1]);

        self::assertGreaterThan(
            self::countBlocks($chunk, $states, 7, 'minecraft:deepslate'),
            self::countBlocks($chunk, $states, 1, 'minecraft:deepslate'),
        );
        self::assertGreaterThan(
            self::countBlocks($chunk, $states, 1, 'minecraft:stone'),
            self::countBlocks($chunk, $states, 7, 'minecraft:stone'),
        );
    }

    private static function countBlocks(Chunk $chunk, BlockStateRegistry $states, int $y, string $identifier): int
    {
        $count = 0;
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                $count += (int) ($states->state($chunk->blockStateAt($x, $y, $z))->identifier() === $identifier);
            }
        }

        return $count;
    }
}
