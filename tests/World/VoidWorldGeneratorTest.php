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
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\VoidWorldGenerator;
use PHPUnit\Framework\TestCase;

final class VoidWorldGeneratorTest extends TestCase
{
    public function testProducesDeterministicEmptyChunksAtEveryHeight(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $air = $states->internalId(CanonicalBlockState::from('minecraft:air'));
        $generator = new VoidWorldGenerator($air);
        $position = new ChunkPosition(-18, 29);

        $first = $generator->generate($position);
        $second = $generator->generate($position);

        self::assertSame(VoidWorldGenerator::IDENTIFIER, $generator->name());
        self::assertSame(VoidWorldGenerator::VERSION, $generator->version());
        self::assertSame([], $first->populatedSections());
        self::assertSame($air, $first->blockStateAt(0, Chunk::MIN_Y, 0));
        self::assertSame($air, $first->blockStateAt(15, Chunk::MAX_Y, 15));
        self::assertEquals($first, $second);
        self::assertSame([0, 64, 0], [
            $generator->defaultSpawn()->x,
            $generator->defaultSpawn()->y,
            $generator->defaultSpawn()->z,
        ]);
    }
}
