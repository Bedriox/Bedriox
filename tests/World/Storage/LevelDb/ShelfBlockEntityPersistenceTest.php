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

namespace Bedriox\Server\Tests\World\Storage\LevelDb;

use Bedriox\Server\Gameplay\Block\Interaction\ShelfInventory;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockEntity\ShelfBlockEntity;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;
use PHPUnit\Framework\TestCase;

final class ShelfBlockEntityPersistenceTest extends TestCase
{
    public function testThreeShelfCompartmentsSurviveStorageAndNetworkProjection(): void
    {
        $position = new BlockPosition(2, 70, 3);
        $shelf = new ShelfBlockEntity($position, new ShelfInventory([
            0 => new ContainerItemStack('minecraft:stone', 64),
            2 => new ContainerItemStack('minecraft:diamond_sword', 1, 12),
        ]));
        $codec = new PersistentBlockEntityCodec();

        $decoded = $codec->decode(
            $codec->encode(new BlockEntityCollection(new ChunkPosition(0, 0), [$shelf])),
            new ChunkPosition(0, 0),
        )->at($position);

        self::assertInstanceOf(ShelfBlockEntity::class, $decoded);
        self::assertSame('minecraft:stone', $decoded->inventory->stackAt(0)?->identifier);
        self::assertNull($decoded->inventory->stackAt(1));
        self::assertSame(12, $decoded->inventory->stackAt(2)?->damage);
        self::assertNotSame('', $codec->encodeNetworkEntity($shelf));
    }
}
