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

namespace Bedriox\Server\Tests\World\BlockEntity;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContainerBlockEntityTest extends TestCase
{
    public function testContainerMutationBecomesOneDirtyChunkRevision(): void
    {
        $air = CanonicalBlockState::from('minecraft:air');
        $states = new BlockStateRegistry([$air]);
        $position = new BlockPosition(17, 64, -31);
        $container = ContainerBlockEntity::empty(BlockEntityType::Chest, $position);
        $chunk = new Chunk(new ChunkPosition(1, -2), $states->internalId($air), []);

        $withContainer = $chunk->withBlockEntity($container);
        $inventory = $container->inventory->withStack(3, new ContainerItemStack('minecraft:diamond', 12));
        $changed = $withContainer->withBlockEntity($container->withInventory($inventory));

        self::assertSame(1, $withContainer->revision);
        self::assertSame(2, $changed->revision);
        self::assertTrue($changed->hasDirtyFlag(Chunk::DIRTY_BLOCK_ENTITIES));
        self::assertSame(12, ($changed->blockEntityAt($position) instanceof ContainerBlockEntity)
            ? $changed->blockEntityAt($position)->inventory->stackAt(3)?->count
            : null);

        $pending = $changed->withPersistedRevision(1);
        self::assertTrue($pending->isDirty(), 'An acknowledgement for an older snapshot must not clear newer state.');
        self::assertFalse($changed->withPersistedRevision(2)->isDirty());
    }

    public function testChestPairingRequiresOneHorizontalNeighbour(): void
    {
        $chest = ContainerBlockEntity::empty(BlockEntityType::Chest, new BlockPosition(15, 70, 0));
        $paired = $chest->withPair(new BlockPosition(16, 70, 0), true);

        self::assertSame(16, $paired->pairedPosition?->x);
        self::assertTrue($paired->pairLead);

        $this->expectException(InvalidArgumentException::class);
        $chest->withPair(new BlockPosition(17, 70, 0), false);
    }

    public function testCollectionRejectsEntityFromAnotherChunk(): void
    {
        $air = CanonicalBlockState::from('minecraft:air');
        $states = new BlockStateRegistry([$air]);
        $chunk = new Chunk(new ChunkPosition(0, 0), $states->internalId($air), []);

        $this->expectException(InvalidArgumentException::class);
        $chunk->withBlockEntity(ContainerBlockEntity::empty(
            BlockEntityType::Barrel,
            new BlockPosition(16, 64, 0),
        ));
    }
}
