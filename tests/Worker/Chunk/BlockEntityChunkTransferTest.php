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

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use PHPUnit\Framework\TestCase;

final class BlockEntityChunkTransferTest extends TestCase
{
    public function testAsynchronousChunkSnapshotCarriesContainerState(): void
    {
        $air = CanonicalBlockState::from('minecraft:air');
        $states = new BlockStateRegistry([$air]);
        $position = new BlockPosition(4, 64, 8);
        $entity = ContainerBlockEntity::empty(BlockEntityType::Barrel, $position);
        $entity = $entity->withInventory($entity->inventory->withStack(
            0,
            new ContainerItemStack('minecraft:apple', 10),
        ));
        $chunk = (new Chunk(new ChunkPosition(0, 0), $states->internalId($air), []))->withBlockEntity($entity);
        $codec = new ChunkTransferCodec();

        $decoded = $codec->decode($codec->encode($chunk, $states), $states);
        $loaded = $decoded->blockEntityAt($position);

        self::assertInstanceOf(ContainerBlockEntity::class, $loaded);
        self::assertSame(10, $loaded->inventory->stackAt(0)?->count);
        self::assertSame($chunk->revision, $decoded->revision);
        self::assertSame($chunk->dirtyFlags, $decoded->dirtyFlags);
    }
}
