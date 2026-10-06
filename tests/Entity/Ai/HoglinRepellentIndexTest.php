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

namespace Bedriox\Server\Tests\Entity\Ai;

use Bedriox\Server\Entity\Ai\Sensor\HoglinRepellentIndex;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use PHPUnit\Framework\TestCase;

final class HoglinRepellentIndexTest extends TestCase
{
    public function testKnownRepellentsProduceBoundedMotionAwayFromNearestBlock(): void
    {
        $index = new HoglinRepellentIndex(4);
        $index->update('nether', new BlockPosition(2, 64, 0), 'minecraft:warped_fungus');
        $index->update('nether', new BlockPosition(-7, 64, 0), 'minecraft:respawn_anchor');

        $nearest = $index->nearest('nether', new Position(0.5, 64.0, 0.5));
        self::assertNotNull($nearest);
        self::assertSame(2, $nearest->x);
        $motion = $index->avoidanceMotion('nether', new Position(0.5, 64.0, 0.5));
        self::assertNotNull($motion);
        self::assertLessThan(0.0, $motion->x);
        self::assertEqualsWithDelta(0.28, hypot($motion->x, $motion->z), 0.000_001);

        $index->update('nether', new BlockPosition(2, 64, 0), 'minecraft:air');
        self::assertNull($index->nearest('nether', new Position(0.5, 64.0, 0.5), 4.0));
    }

    public function testRepellentIdentityIsExplicit(): void
    {
        self::assertTrue(HoglinRepellentIndex::isRepellent('minecraft:nether_portal'));
        self::assertTrue(HoglinRepellentIndex::isRepellent('minecraft:potted_warped_fungus'));
        self::assertFalse(HoglinRepellentIndex::isRepellent('minecraft:crimson_fungus'));
    }

    public function testUnloadedChunkRemovalPreservesOtherIndexedChunks(): void
    {
        $index = new HoglinRepellentIndex(4);
        $index->update('nether', new BlockPosition(2, 64, 2), 'minecraft:warped_fungus');
        $index->update('nether', new BlockPosition(18, 64, 2), 'minecraft:respawn_anchor');

        $index->removeChunk('nether', new ChunkPosition(0, 0));

        self::assertSame(1, $index->count());
        self::assertSame(18, $index->nearest('nether', new Position(18.5, 64.0, 2.5))?->x);
    }
}
