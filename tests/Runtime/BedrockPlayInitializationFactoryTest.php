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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Inventory\ItemDefinition;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AvailableActorIdentifiersPacket;
use Bedriox\Protocol\Packet\BiomeDefinitionListPacket;
use Bedriox\Protocol\Packet\ChunkRadiusUpdatedPacket;
use Bedriox\Protocol\Packet\CraftingDataPacket;
use Bedriox\Protocol\Packet\CraftingRecipe;
use Bedriox\Protocol\Packet\CreativeContentPacket;
use Bedriox\Protocol\Packet\GameRulesChangedPacket;
use Bedriox\Protocol\Packet\InventoryContainerId;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\ItemRegistryPacket;
use Bedriox\Protocol\Packet\JigsawStructureDataPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\PlayerListAddPacket;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetCommandsEnabledPacket;
use Bedriox\Protocol\Packet\SetDifficultyPacket;
use Bedriox\Protocol\Packet\SetLocalPlayerAsInitializedPacket;
use Bedriox\Protocol\Packet\SetSpawnPositionPacket;
use Bedriox\Protocol\Packet\SetTimePacket;
use Bedriox\Protocol\Packet\StartGamePacket;
use Bedriox\Protocol\Packet\TrimDataPacket;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\Packet\UpdateAdventureSettingsPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Packet\VoxelShapesPacket;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Crafting\RecipeIngredient;
use Bedriox\Server\Gameplay\Crafting\RecipeOutput;
use Bedriox\Server\Gameplay\Crafting\ShapelessRecipe;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Plugin\OwnedItemRegistrar;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Runtime\BedrockPlayInitializationFactory;
use Bedriox\Server\Runtime\ReusablePlayPacket;
use Bedriox\Server\Runtime\RuntimeLimits;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class BedrockPlayInitializationFactoryTest extends TestCase
{
    public function testInitializationUsesTheCurrentCraftingCatalogRevision(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $catalog = CraftingCatalog::fromData(
            $data,
            $items,
            $states,
            BedrockInventoryPacketProjector::fromData(
                $data,
                new BlockNetworkTranslator($states, $data->blockStateRegistry()),
                $items,
            ),
        );
        $factory = new BedrockPlayInitializationFactory(
            $data,
            itemCatalog: $items,
            craftingCatalog: $catalog,
        );

        $catalog->register(new ShapelessRecipe(
            'example:initial_recipe',
            [RecipeIngredient::exact('minecraft:stone')],
            [new RecipeOutput('minecraft:dirt')],
            recipeOwner: 'Example',
        ));
        $packets = self::packets($factory->create($this->login(), UnsignedLong::fromInt(7)));

        self::assertInstanceOf(CraftingDataPacket::class, $packets[22]);
        self::assertCount(4_497, $packets[22]->recipes);
        self::assertNotEmpty(array_filter(
            $packets[22]->recipes,
            static fn(CraftingRecipe $recipe): bool => $recipe->networkId()
                === $catalog->recipes()->networkId('example:initial_recipe'),
        ));
    }

    public function testAuthoritativeWorldDataDrivesInitializationMetadataAndTime(): void
    {
        $login = $this->login();
        $worldData = new WorldData(
            new WorldMetadata('Persisted World', -912),
            'flat',
            new SpawnPosition(19, 77, -6),
            3456,
            3,
        );
        $packets = self::packets(BedrockPlayInitializationFactory::forWorld(
            BedrockDataSet::bundled(),
            new RuntimeLimits(),
            $worldData,
            300,
        )->create($login, UnsignedLong::fromInt(7)));
        $expected = self::packets((new BedrockPlayInitializationFactory(
            BedrockDataSet::bundled(),
            new RuntimeLimits(),
            'Persisted World',
            new SpawnPosition(19, 77, -6),
            3,
            -912,
            3456,
            'flat',
            300,
        ))->create($login, UnsignedLong::fromInt(7)));

        self::assertSame($expected[2]->encode(), $packets[2]->encode());
        self::assertInstanceOf(SetSpawnPositionPacket::class, $packets[7]);
        self::assertSame([19, 77, -6], [$packets[7]->x, $packets[7]->y, $packets[7]->z]);
        self::assertInstanceOf(SetTimePacket::class, $packets[8]);
        self::assertSame(3456, $packets[8]->time);
        self::assertInstanceOf(SetDifficultyPacket::class, $packets[9]);
        self::assertSame(3, $packets[9]->difficulty);
    }

    public function testNewPlayersReceiveTheCurrentWorldTimeInsteadOfTheStartupSnapshot(): void
    {
        $time = 1_000;
        $factory = BedrockPlayInitializationFactory::forWorld(
            BedrockDataSet::bundled(),
            new RuntimeLimits(),
            new WorldData(
                new WorldMetadata('Dynamic Time', 1),
                'flat',
                new SpawnPosition(0, 64, 0),
                $time,
            ),
            worldTimeProvider: static function () use (&$time): int {
                return $time;
            },
        );

        $time = 13_000;
        $packets = self::packets($factory->create($this->login(), UnsignedLong::fromInt(7)));

        self::assertInstanceOf(SetTimePacket::class, $packets[8]);
        self::assertSame(13_000, $packets[8]->time);
    }

    public function testExactOrderGoldenRegistryAndDefersTerrainToRuntimeStreamer(): void
    {
        $login = $this->login();
        $packets = self::packets((new BedrockPlayInitializationFactory(BedrockDataSet::bundled()))
            ->create($login, UnsignedLong::fromInt(7)));

        self::assertCount(24, $packets);
        self::assertInstanceOf(JigsawStructureDataPacket::class, $packets[0]);
        self::assertInstanceOf(VoxelShapesPacket::class, $packets[1]);
        self::assertInstanceOf(StartGamePacket::class, $packets[2]);
        self::assertSame(76_835, strlen($packets[2]->encode()));
        self::assertTrue($packets[2]->blockNetworkIdsAreHashes);
        self::assertSame('69d991a3ffd1537e61ad502b8e0529a52fea2c38b82356b13f8077c91cf69e36', hash('sha256', $packets[2]->encode()));
        self::assertInstanceOf(ItemRegistryPacket::class, $packets[3]);
        self::assertSame(166_607, strlen($packets[3]->encode()));
        self::assertSame('0cbe4e9e93a3003e8f8cf4f322ed36a18a373b2000fbaac8c164c5320ba2e7af', hash('sha256', $packets[3]->encode()));
        self::assertInstanceOf(ChunkRadiusUpdatedPacket::class, $packets[4]);
        self::assertSame(1, $packets[4]->radius);
        self::assertInstanceOf(BiomeDefinitionListPacket::class, $packets[5]);
        self::assertInstanceOf(AvailableActorIdentifiersPacket::class, $packets[6]);
        self::assertInstanceOf(SetSpawnPositionPacket::class, $packets[7]);
        self::assertSame([0, 64, 0], [$packets[7]->x, $packets[7]->y, $packets[7]->z]);
        self::assertInstanceOf(SetTimePacket::class, $packets[8]);
        self::assertInstanceOf(SetDifficultyPacket::class, $packets[9]);
        self::assertSame(2, $packets[9]->difficulty);
        self::assertInstanceOf(SetCommandsEnabledPacket::class, $packets[10]);
        self::assertInstanceOf(UpdateAbilitiesPacket::class, $packets[11]);
        self::assertInstanceOf(UpdateAdventureSettingsPacket::class, $packets[12]);
        self::assertInstanceOf(GameRulesChangedPacket::class, $packets[13]);
        self::assertInstanceOf(PlayerListAddPacket::class, $packets[14]);
        self::assertSame(BuildPlatform::Unknown, $packets[14]->entries[0]->buildPlatform);
        self::assertInstanceOf(UpdateAttributesPacket::class, $packets[15]);
        self::assertInstanceOf(CreativeContentPacket::class, $packets[16]);
        foreach ([[17, 0, 36], [18, 120, 4], [19, 119, 1]] as [$index, $windowId, $slotCount]) {
            self::assertInstanceOf(InventoryContentPacket::class, $packets[$index]);
            self::assertSame($windowId, $packets[$index]->windowId);
            self::assertCount($slotCount, $packets[$index]->items);
        }
        $mainInventory = $packets[17];
        self::assertInstanceOf(InventoryContentPacket::class, $mainInventory);
        self::assertSame(2, $mainInventory->items[0]->runtimeId);
        self::assertSame(64, $mainInventory->items[0]->count);
        self::assertSame(1, $mainInventory->items[0]->stackNetworkId);
        self::assertSame(-567_203_660, $mainInventory->items[0]->blockRuntimeId);
        foreach (array_slice($mainInventory->items, 1) as $empty) {
            self::assertSame(0, $empty->runtimeId);
            self::assertSame(1, $empty->count);
        }
        $equipment = $packets[20];
        self::assertInstanceOf(MobEquipmentPacket::class, $equipment);
        self::assertEquals($mainInventory->items[0], $equipment->item);
        self::assertInstanceOf(TrimDataPacket::class, $packets[21]);
        self::assertInstanceOf(CraftingDataPacket::class, $packets[22]);
        self::assertTrue($packets[22]->cleanRecipes);
        self::assertCount(4_496, $packets[22]->recipes);
        self::assertCount(4_496, array_unique(array_map(
            static fn(CraftingRecipe $recipe): int => $recipe->networkId(),
            $packets[22]->recipes,
        )));
        $decodedCrafting = CraftingDataPacket::decode($packets[22]->encode());
        self::assertTrue($decodedCrafting->cleanRecipes);
        self::assertCount(4_496, $decodedCrafting->recipes);
        self::assertNotEmpty($decodedCrafting->potionMixData);
        self::assertNotEmpty($decodedCrafting->containerMixData);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[23]);
        self::assertSame(400, $packets[23]->metadata[4]->value);
        self::assertSame(400, $packets[23]->metadata[7]->value);
        foreach ($packets as $packet) {
            self::assertNotInstanceOf(SetLocalPlayerAsInitializedPacket::class, $packet);
        }
    }

    public function testRestoredBootstrapDrivesTheInitialInventoryAndEquipmentPackets(): void
    {
        $login = $this->login();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity($login->identity, $login->displayName, $login->xuid),
            'world',
            new Position(32.5, 80.0, -18.25),
            90.0,
            5.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(3, new PlayerInventoryStackState('minecraft:grass_block', 11)),
            ], 3, armor: [
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:iron_helmet', 1)),
            ], offhand: new PlayerInventoryStackState('minecraft:grass_block', 3)),
            100,
            200,
            health: 7.5,
        );

        $packets = self::packets((new BedrockPlayInitializationFactory(BedrockDataSet::bundled()))
            ->create($login, UnsignedLong::fromInt(7), $bootstrap));

        $startGame = $packets[2];
        self::assertInstanceOf(StartGamePacket::class, $startGame);
        $startBytes = $startGame->encode();
        $pitch = unpack('gvalue', substr($startBytes, 15, 4));
        $yaw = unpack('gvalue', substr($startBytes, 19, 4));
        self::assertIsArray($pitch);
        self::assertIsArray($yaw);
        self::assertEqualsWithDelta(5.0, $pitch['value'], 0.0001);
        self::assertEqualsWithDelta(90.0, $yaw['value'], 0.0001);
        $attributes = $packets[15];
        self::assertInstanceOf(UpdateAttributesPacket::class, $attributes);
        self::assertSame(7.5, $attributes->attributes[0]->value);
        $inventory = $packets[17];
        self::assertInstanceOf(InventoryContentPacket::class, $inventory);
        self::assertSame(0, $inventory->items[0]->runtimeId);
        self::assertSame(11, $inventory->items[3]->count);
        self::assertSame(1, $inventory->items[3]->stackNetworkId);
        $armor = $packets[18];
        self::assertInstanceOf(InventoryContentPacket::class, $armor);
        self::assertSame(InventoryContainerId::ARMOR, $armor->windowId);
        self::assertNotSame(0, $armor->items[0]->runtimeId);
        self::assertSame(1, $armor->items[0]->count);
        self::assertSame(2, $armor->items[0]->stackNetworkId);
        $offhand = $packets[19];
        self::assertInstanceOf(InventoryContentPacket::class, $offhand);
        self::assertSame(InventoryContainerId::OFFHAND, $offhand->windowId);
        self::assertSame(3, $offhand->items[0]->count);
        self::assertSame(3, $offhand->items[0]->stackNetworkId);
        $equipment = $packets[20];
        self::assertInstanceOf(MobEquipmentPacket::class, $equipment);
        self::assertSame(3, $equipment->inventorySlot);
        self::assertSame(3, $equipment->hotbarSlot);
        self::assertEquals($inventory->items[3], $equipment->item);
    }

    public function testInitializationUsesTheSharedLiveItemCatalog(): void
    {
        $data = BedrockDataSet::bundled();
        $catalog = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        (new OwnedItemRegistrar('TestPlugin', $catalog))->register(
            new ItemDefinition('minecraft:grass_block', creative: false),
            true,
        );

        $packets = self::packets((new BedrockPlayInitializationFactory($data, itemCatalog: $catalog))
            ->create($this->login(), UnsignedLong::fromInt(7)));
        $creative = $packets[16];
        self::assertInstanceOf(CreativeContentPacket::class, $creative);
        $grassRuntimeId = $data->itemNetworkRegistry()
            ->definitionForIdentifier('minecraft:grass_block')
            ->networkRuntimeId();
        foreach ($creative->entries as $entry) {
            self::assertNotSame($grassRuntimeId, $entry->item->runtimeId);
        }
    }

    private function login(): AuthenticatedLogin
    {
        $key = (new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf'))->generate()->publicKey;

        return new AuthenticatedLogin(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            '1',
            $key,
            new VerifiedClientData(
                64,
                32,
                str_repeat("\0", 64 * 32 * 4),
                0,
                0,
                '',
                '{}',
                [],
                skinId: 'skin',
                skinResourcePatchJson: '{"geometry":{"default":"geometry.humanoid.custom"}}',
            ),
        );
    }

    /**
     * @param list<\Bedriox\Protocol\Packet\Packet|ReusablePlayPacket> $entries
     * @return list<\Bedriox\Protocol\Packet\Packet>
     */
    private static function packets(array $entries): array
    {
        return array_map(
            static fn($entry) => $entry instanceof ReusablePlayPacket ? $entry->packet : $entry,
            $entries,
        );
    }
}
