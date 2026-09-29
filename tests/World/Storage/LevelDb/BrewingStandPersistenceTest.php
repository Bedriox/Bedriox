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

use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;
use PHPUnit\Framework\TestCase;

final class BrewingStandPersistenceTest extends TestCase
{
    public function testRoundTripsInventoryProgressFuelAndName(): void
    {
        $entity = new BrewingStandBlockEntity(
            new BlockPosition(1, 64, 2),
            new ContainerInventory(BrewingStandBlockEntity::SLOT_COUNT, [
                BrewingStandBlockEntity::SLOT_INGREDIENT => new ContainerItemStack('minecraft:nether_wart', 3),
                BrewingStandBlockEntity::SLOT_BOTTLE_LEFT => new ContainerItemStack(
                    'minecraft:potion',
                    1,
                    auxValue: 4,
                ),
            ]),
            271,
            13,
            20,
            'Potions',
        );
        $codec = new PersistentBlockEntityCodec();
        $encoded = $codec->encode(new BlockEntityCollection(new ChunkPosition(0, 0), [$entity]));
        $decoded = $codec->decode($encoded, new ChunkPosition(0, 0))->at($entity->position);

        self::assertInstanceOf(BrewingStandBlockEntity::class, $decoded);
        self::assertSame(271, $decoded->brewTime);
        self::assertSame(13, $decoded->fuelAmount);
        self::assertSame(20, $decoded->fuelTotal);
        self::assertSame('Potions', $decoded->customName);
        self::assertSame(4, $decoded->inventory->stackAt(BrewingStandBlockEntity::SLOT_BOTTLE_LEFT)?->auxValue);
        self::assertNotSame('', $codec->encodeNetworkEntity($decoded));
    }
}
