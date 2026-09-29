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

namespace Bedriox\Server\Tests\Inventory;

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
use Bedriox\Server\Inventory\ContainerRevisionMismatchException;
use Bedriox\Server\Inventory\WorldContainerStore;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class WorldContainerStoreTest extends TestCase
{
    public function testBrewingStandUsesItsFiveSlotLayoutAndPreservesProgress(): void
    {
        $world = $this->world();
        $position = new BlockPosition(2, 64, 2);
        $world->setBlockEntity(new BrewingStandBlockEntity(
            $position,
            ContainerInventory::empty(BrewingStandBlockEntity::SLOT_COUNT),
            brewTime: 200,
            fuelAmount: 7,
            fuelTotal: 20,
        ));
        $store = new WorldContainerStore($world);
        $resolved = $store->resolve($position, 'minecraft:brewing_stand');
        self::assertNotNull($resolved);
        self::assertSame(5, $resolved->inventory->size());
        $contents = array_fill(0, 5, null);
        $contents[BrewingStandBlockEntity::SLOT_FUEL] = new ItemStack('minecraft:blaze_powder', 3);

        self::assertTrue($store->replaceAndPersist($resolved, $contents, $resolved->inventory->revision()));
        $persisted = $world->blockEntityAt($position);
        self::assertInstanceOf(BrewingStandBlockEntity::class, $persisted);
        self::assertSame(200, $persisted->brewTime);
        self::assertSame(7, $persisted->fuelAmount);
        self::assertSame('minecraft:blaze_powder', $persisted->inventory->stackAt(4)?->identifier);
    }

    public function testPairedChestReplacementPublishesBothDurableHalvesAndLiveContents(): void
    {
        $world = $this->world();
        $leftPosition = new BlockPosition(15, 64, 0);
        $rightPosition = new BlockPosition(16, 64, 0);
        $world->setBlockEntities(
            new ContainerBlockEntity(
                BlockEntityType::Chest,
                $leftPosition,
                ContainerInventory::empty(ContainerBlockEntity::STORAGE_SLOT_COUNT),
                pairedPosition: $rightPosition,
                pairLead: true,
            ),
            new ContainerBlockEntity(
                BlockEntityType::Chest,
                $rightPosition,
                ContainerInventory::empty(ContainerBlockEntity::STORAGE_SLOT_COUNT),
                pairedPosition: $leftPosition,
            ),
        );
        $store = new WorldContainerStore($world);
        $resolved = $store->resolve($leftPosition, 'minecraft:chest');
        self::assertNotNull($resolved);
        $contents = array_fill(0, 54, null);
        $contents[0] = new ItemStack('minecraft:stone', 12);
        $contents[53] = new ItemStack('minecraft:apple', 3);

        self::assertTrue($store->replaceAndPersist($resolved, $contents, $resolved->inventory->revision()));

        self::assertSame('minecraft:stone', $resolved->inventory->stackAt(0)?->identifier);
        self::assertSame('minecraft:apple', $resolved->inventory->stackAt(53)?->identifier);
        self::assertFalse($resolved->inventory->isDirty());
        $left = $world->blockEntityAt($leftPosition);
        $right = $world->blockEntityAt($rightPosition);
        self::assertInstanceOf(ContainerBlockEntity::class, $left);
        self::assertInstanceOf(ContainerBlockEntity::class, $right);
        self::assertSame('minecraft:stone', $left->inventory->stackAt(0)?->identifier);
        self::assertSame('minecraft:apple', $right->inventory->stackAt(26)?->identifier);
    }

    public function testStalePairedChestReplacementCannotChangeEitherHalf(): void
    {
        $world = $this->world();
        $leftPosition = new BlockPosition(0, 64, 0);
        $rightPosition = new BlockPosition(1, 64, 0);
        $world->setBlockEntities(
            new ContainerBlockEntity(
                BlockEntityType::Chest,
                $leftPosition,
                ContainerInventory::empty(ContainerBlockEntity::STORAGE_SLOT_COUNT),
                pairedPosition: $rightPosition,
                pairLead: true,
            ),
            new ContainerBlockEntity(
                BlockEntityType::Chest,
                $rightPosition,
                ContainerInventory::empty(ContainerBlockEntity::STORAGE_SLOT_COUNT),
                pairedPosition: $leftPosition,
            ),
        );
        $store = new WorldContainerStore($world);
        $resolved = $store->resolve($leftPosition, 'minecraft:chest');
        self::assertNotNull($resolved);
        $staleRevision = $resolved->inventory->revision();
        $resolved->inventory->setStack(0, new ItemStack('minecraft:dirt', 1));
        $replacement = array_fill(0, 54, null);
        $replacement[27] = new ItemStack('minecraft:apple', 1);

        try {
            $store->replaceAndPersist($resolved, $replacement, $staleRevision);
            self::fail('A stale combined revision was accepted.');
        } catch (ContainerRevisionMismatchException) {
            self::assertSame('minecraft:dirt', $resolved->inventory->stackAt(0)?->identifier);
            $left = $world->blockEntityAt($leftPosition);
            $right = $world->blockEntityAt($rightPosition);
            self::assertInstanceOf(ContainerBlockEntity::class, $left);
            self::assertInstanceOf(ContainerBlockEntity::class, $right);
            self::assertNull($left->inventory->stackAt(0));
            self::assertNull($right->inventory->stackAt(0));
        }
    }

    private function world(): World
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($registry));

        return new World(new WorldMetadata('container-test', 0), $generator, new ChunkRepository(4));
    }
}
