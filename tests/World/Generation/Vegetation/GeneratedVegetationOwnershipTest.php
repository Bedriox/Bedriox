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

final class GeneratedVegetationOwnershipTest extends TestCase
{
    public function testSeamTreeIsByteStableWhenNeighborGenerationOrderIsReversed(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $forward = new DefaultWorldGenerator(9_123, $states);
        $forwardWest = $forward->generate(new ChunkPosition(0, 0));
        $forwardEast = $forward->generate(new ChunkPosition(1, 0));

        $reverse = new DefaultWorldGenerator(9_123, $states);
        $reverseEast = $reverse->generate(new ChunkPosition(1, 0));
        $reverseWest = $reverse->generate(new ChunkPosition(0, 0));

        self::assertSame(self::signature($forwardWest), self::signature($reverseWest));
        self::assertSame(self::signature($forwardEast), self::signature($reverseEast));

        $crossingBlocks = 0;
        for ($y = Chunk::MIN_Y; $y <= Chunk::MAX_Y; ++$y) {
            for ($z = 0; $z < 16; ++$z) {
                $west = $states->state($forwardWest->blockStateAt(15, $y, $z))->identifier();
                $east = $states->state($forwardEast->blockStateAt(0, $y, $z))->identifier();
                $crossingBlocks += (int) (self::isVegetation($west) && self::isVegetation($east));
            }
        }
        self::assertGreaterThan(0, $crossingBlocks, 'The selected chunks must exercise a rendered tree crossing their seam.');
    }

    private static function signature(Chunk $chunk): string
    {
        $hash = hash_init('xxh3');
        for ($y = Chunk::MIN_Y; $y <= Chunk::MAX_Y; ++$y) {
            for ($z = 0; $z < 16; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    hash_update($hash, pack('V', $chunk->blockStateAt($x, $y, $z)->value));
                }
            }
        }

        return hash_final($hash);
    }

    private static function isVegetation(string $identifier): bool
    {
        return str_ends_with($identifier, '_log') || str_ends_with($identifier, '_leaves');
    }
}
