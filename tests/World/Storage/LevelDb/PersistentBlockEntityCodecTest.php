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
use Bedriox\Protocol\Packet\LittleEndianNbtToNetwork;
use Bedriox\Server\Gameplay\Processing\CampfireBlockEntity;
use Bedriox\Server\Gameplay\Processing\CampfireType;
use Bedriox\Server\Gameplay\Processing\CauldronBlockEntity;
use Bedriox\Server\Gameplay\Processing\FurnaceBlockEntity;
use Bedriox\Server\Gameplay\Processing\FurnaceType;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Storage\LevelDb\LevelDbStorageException;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use PHPUnit\Framework\TestCase;

final class PersistentBlockEntityCodecTest extends TestCase
{
    public function testPotionCauldronMetadataRoundTripsAndProjectsToNetworkNbt(): void
    {
        $chunk = new ChunkPosition(0, 0);
        $entity = new CauldronBlockEntity(new BlockPosition(3, 70, 4), 17);
        $codec = new PersistentBlockEntityCodec();

        $decoded = $codec->decode($codec->encode(new BlockEntityCollection($chunk, [$entity])), $chunk)
            ->at($entity->position);

        self::assertInstanceOf(CauldronBlockEntity::class, $decoded);
        self::assertSame(17, $decoded->potionAuxValue);
        self::assertNotSame('', $codec->encodeNetworkEntity($decoded));
    }

    public function testProcessingStationsRoundTripProgressAndInventories(): void
    {
        $chunk = new ChunkPosition(0, 0);
        $furnace = FurnaceBlockEntity::empty(FurnaceType::BlastFurnace, new BlockPosition(1, 64, 1));
        $furnace = $furnace->withState(
            $furnace->inventory->withStack(0, new ContainerItemStack('minecraft:raw_iron', 3)),
            80,
            100,
            42,
            700,
        );
        $campfire = CampfireBlockEntity::empty(CampfireType::SoulCampfire, new BlockPosition(2, 64, 1));
        $campfire = $campfire->withState(
            $campfire->inventory->withStack(2, new ContainerItemStack('minecraft:beef', 1)),
            [2 => 125],
            [2 => 600],
        );
        $codec = new PersistentBlockEntityCodec();

        $decoded = $codec->decode($codec->encode(new BlockEntityCollection($chunk, [$furnace, $campfire])), $chunk);

        $loadedFurnace = $decoded->at($furnace->position);
        self::assertInstanceOf(FurnaceBlockEntity::class, $loadedFurnace);
        self::assertSame(FurnaceType::BlastFurnace, $loadedFurnace->furnaceType);
        self::assertSame(42, $loadedFurnace->cookTime);
        self::assertSame(700, $loadedFurnace->storedExperienceMilli);
        $loadedCampfire = $decoded->at($campfire->position);
        self::assertInstanceOf(CampfireBlockEntity::class, $loadedCampfire);
        self::assertSame(CampfireType::SoulCampfire, $loadedCampfire->campfireType);
        self::assertSame(125, $loadedCampfire->progressBySlot[2]);
    }

    public function testContainerDataUsesMojangFieldsAndRoundTripsCanonicalItems(): void
    {
        $position = new ChunkPosition(-1, 2);
        $blockPosition = new BlockPosition(-1, 70, 32);
        $customNbt = ItemNbt::empty()->withString('bedriox_test', 'preserved');
        $entity = ContainerBlockEntity::empty(BlockEntityType::Chest, $blockPosition)
            ->withCustomName('Storage')
            ->withPair(new BlockPosition(-2, 70, 32), true);
        $entity = $entity->withInventory($entity->inventory->withStack(
            5,
            new ContainerItemStack('minecraft:iron_pickaxe', 1, 17, $customNbt, 3),
        ));
        $codec = new PersistentBlockEntityCodec();

        $encoded = $codec->encode(new BlockEntityCollection($position, [$entity]));
        $root = (new LittleEndianNbtCodec())->decodeRootCompounds($encoded, 1)[0];
        self::assertSame('Chest', $root['id']->value);
        self::assertSame(-1, $root['x']->value);
        self::assertSame('Storage', $root['CustomName']->value);
        self::assertSame(LittleEndianNbtTag::LIST, $root['Items']->type);

        $decoded = $codec->decode($encoded, $position)->at($blockPosition);
        self::assertInstanceOf(ContainerBlockEntity::class, $decoded);
        self::assertSame('Storage', $decoded->customName);
        self::assertSame(-2, $decoded->pairedPosition?->x);
        self::assertTrue($decoded->pairLead);
        $stack = $decoded->inventory->stackAt(5);
        self::assertNotNull($stack);
        self::assertSame('minecraft:iron_pickaxe', $stack->identifier);
        self::assertSame(17, $stack->damage);
        self::assertSame(3, $stack->auxValue);
        self::assertSame('preserved', $stack->nbt?->string('bedriox_test'));
    }

    public function testShulkerFacingAndEmptyEnderChestRoundTrip(): void
    {
        $position = new ChunkPosition(0, 0);
        $shulker = ContainerBlockEntity::empty(BlockEntityType::ShulkerBox, new BlockPosition(1, 80, 1))
            ->withFacing(5);
        $ender = (new \Bedriox\Server\World\BlockEntity\BlockEntityRegistry())
            ->create(BlockEntityType::EnderChest, new BlockPosition(2, 80, 1));
        $codec = new PersistentBlockEntityCodec();

        $decoded = $codec->decode($codec->encode(new BlockEntityCollection($position, [$shulker, $ender])), $position);

        $loadedShulker = $decoded->at($shulker->position);
        self::assertInstanceOf(ContainerBlockEntity::class, $loadedShulker);
        self::assertSame(5, $loadedShulker->facing);
        self::assertSame(BlockEntityType::EnderChest, $decoded->at($ender->position)?->type);
    }

    public function testNetworkProjectionIncludesOnlyClientVisibleContainerState(): void
    {
        $position = new ChunkPosition(0, 0);
        $chest = ContainerBlockEntity::empty(BlockEntityType::Chest, new BlockPosition(1, 70, 1))
            ->withCustomName('Supplies')
            ->withPair(new BlockPosition(2, 70, 1), true);
        $chest = $chest->withInventory($chest->inventory->withStack(
            4,
            new ContainerItemStack('minecraft:diamond', 12),
        ));
        $shulker = ContainerBlockEntity::empty(BlockEntityType::ShulkerBox, new BlockPosition(3, 70, 1))
            ->withFacing(5);
        $codec = new PersistentBlockEntityCodec();

        $encoded = $codec->encodeNetwork(new BlockEntityCollection($position, [$chest, $shulker]));
        $nbt = new LittleEndianNbtCodec();
        $expectedChest = LittleEndianNbtToNetwork::convert($nbt->encodeRootCompound([
            'id' => LittleEndianNbtTag::string('Chest'),
            'x' => LittleEndianNbtTag::int(1),
            'y' => LittleEndianNbtTag::int(70),
            'z' => LittleEndianNbtTag::int(1),
            'CustomName' => LittleEndianNbtTag::string('Supplies'),
            'pairx' => LittleEndianNbtTag::int(2),
            'pairz' => LittleEndianNbtTag::int(1),
        ]));
        $expectedShulker = LittleEndianNbtToNetwork::convert($nbt->encodeRootCompound([
            'id' => LittleEndianNbtTag::string('ShulkerBox'),
            'x' => LittleEndianNbtTag::int(3),
            'y' => LittleEndianNbtTag::int(70),
            'z' => LittleEndianNbtTag::int(1),
            'facing' => LittleEndianNbtTag::byte(5),
        ]));

        self::assertSame([$expectedChest, $expectedShulker], $encoded);
        self::assertStringNotContainsString('Items', implode('', $encoded));
    }

    public function testEmptyCollectionHasNoNetworkProjection(): void
    {
        self::assertSame(
            [],
            (new PersistentBlockEntityCodec())->encodeNetwork(new BlockEntityCollection(new ChunkPosition(4, -2))),
        );
    }

    public function testUnknownAndMisplacedBlockEntitiesFailWithoutPartialRecovery(): void
    {
        $nbt = new LittleEndianNbtCodec();
        $unknown = $nbt->encodeRootCompounds([[
            'id' => LittleEndianNbtTag::string('Unsupported'),
            'x' => LittleEndianNbtTag::int(0),
            'y' => LittleEndianNbtTag::int(64),
            'z' => LittleEndianNbtTag::int(0),
        ]], 1);
        try {
            (new PersistentBlockEntityCodec())->decode($unknown, new ChunkPosition(0, 0));
            self::fail('An unknown block entity was admitted.');
        } catch (LevelDbStorageException) {
            self::addToAssertionCount(1);
        }

        $misplaced = $nbt->encodeRootCompounds([[
            'id' => LittleEndianNbtTag::string('EnderChest'),
            'x' => LittleEndianNbtTag::int(16),
            'y' => LittleEndianNbtTag::int(64),
            'z' => LittleEndianNbtTag::int(0),
        ]], 1);
        $this->expectException(LevelDbStorageException::class);
        (new PersistentBlockEntityCodec())->decode($misplaced, new ChunkPosition(0, 0));
    }

    public function testOversizedRecordFailsBeforeNbtAllocation(): void
    {
        $this->expectException(LevelDbStorageException::class);
        (new PersistentBlockEntityCodec())->decode(
            str_repeat("\0", PersistentBlockEntityCodec::MAXIMUM_BYTES + 1),
            new ChunkPosition(0, 0),
        );
    }
}
