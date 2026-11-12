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

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockEntity\ItemFrameBlockEntity;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;
use PHPUnit\Framework\TestCase;

final class ItemFrameBlockEntityPersistenceTest extends TestCase
{
    public function testMapBearingGlowFrameRoundTripsAndProjectsToNetworkNbt(): void
    {
        $position = new BlockPosition(2, 70, 3);
        $frame = new ItemFrameBlockEntity(
            $position,
            new ContainerItemStack(
                'minecraft:filled_map',
                1,
                nbt: ItemNbt::empty()->withTag('map_uuid', Tag::long(42)),
                auxValue: 6,
            ),
            3,
            0.75,
            true,
            true,
        );
        $codec = new PersistentBlockEntityCodec();
        $chunk = new ChunkPosition(0, 0);
        $loaded = $codec->decode(
            $codec->encode(new BlockEntityCollection($chunk, [$frame])),
            $chunk,
        )->at($position);

        self::assertInstanceOf(ItemFrameBlockEntity::class, $loaded);
        self::assertTrue($loaded->isGlowing());
        self::assertSame('minecraft:filled_map', $loaded->item?->identifier);
        self::assertSame(3, $loaded->rotation);
        self::assertSame(0.75, $loaded->dropChance);
        self::assertTrue($loaded->fixed);
        self::assertNotSame('', $codec->encodeNetworkEntity($loaded));
    }

    public function testEmptyFrameOmitsTheItemCompound(): void
    {
        $networkNbt = (new PersistentBlockEntityCodec())->encodeNetworkEntity(new ItemFrameBlockEntity(
            new BlockPosition(2, 70, 3),
        ));

        self::assertStringNotContainsString('minecraft:air', $networkNbt);
        self::assertStringNotContainsString("\x0a\x04\x00Item", $networkNbt);
    }
}
