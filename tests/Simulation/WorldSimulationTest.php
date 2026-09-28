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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Player\GameMode;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\MultiCraftingRecipe;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Crafting\ComplexCraftingRecipeEvaluator;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\Command\CraftingRequest;
use Bedriox\Server\Simulation\Command\JoinPlayer as UnvalidatedJoinPlayer;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\BlockPunch;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\CraftingTableOpened;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\ItemEntityMoved;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\NutritionChanged;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\NutritionChangeReason;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\SimulationLimits;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\VanillaBlockStates;
use Bedriox\Server\World\BlockOverrideStore;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class WorldSimulationTest extends TestCase
{
    public function testSpawnEggCreatesADataAdmittedEntityAndConsumesOneItem(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('entity-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(16),
        );
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $world = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $palette,
            itemCatalog: $items,
            entityTypes: $data->entityTypeRegistry(),
            entityDefinitions: EntityDefinitionRegistry::fromData($data->entityTypeRegistry()),
        );
        $factory = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity-one', 'One'),
            'entity-test',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:cow_spawn_egg', 2)),
            ], 0),
            1,
            1,
        );
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One', bootstrap: $bootstrap)));
        $world->tick();

        self::assertTrue($world->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(1, 63, 0),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $events = $world->tick()->events;
        $spawned = array_values(array_filter($events, static fn($event): bool => $event instanceof EntityActorSpawned));

        self::assertCount(1, $spawned);
        self::assertSame('minecraft:cow', $spawned[0]->entity->getType()->identifier());
        self::assertSame(1, $world->entityRuntime()->registry()->count());
        self::assertSame(1, $world->pluginPlayer('identity-one')?->getInventory()->getItem(0)?->count);
    }

    public function testGiveSynchronizesOnlyChangedInventorySlots(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new WorldSimulation(
            blockPalette: $palette,
            itemCatalog: ItemCatalog::vanilla($data->itemNetworkRegistry()),
        );
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->giveItem('one', 'minecraft:apple', 65)));
        $event = $world->tick()->events[0];

        self::assertInstanceOf(InventoryStackRequestProcessed::class, $event);
        self::assertTrue($event->success);
        self::assertSame(InventoryResponseMode::LegacySlotSync, $event->responseMode);
        self::assertFalse($event->selectedStackChanged);
        self::assertSame([1, 2], array_map(
            static fn(InventorySlotReference $reference): int => $reference->slot,
            $event->affectedSlots,
        ));
    }

    public function testRequestedInventorySlotSyncDoesNotEscalateToFullContents(): void
    {
        $world = new WorldSimulation();
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();

        $slots = [
            new InventorySlotReference(InventoryContainer::Main, 0, 0),
            new InventorySlotReference(InventoryContainer::Armor, 0, 0),
        ];
        self::assertTrue($world->enqueue($factory->syncInventorySlots('one', $slots)));
        $event = $world->tick()->events[0];

        self::assertInstanceOf(InventoryStackRequestProcessed::class, $event);
        self::assertTrue($event->success);
        self::assertSame(InventoryResponseMode::LegacySlotSync, $event->responseMode);
        self::assertFalse($event->fullSync);
        self::assertSame($slots, $event->affectedSlots);
    }

    public function testCraftingConsumesTheMatchedGridAndCreatesOnlyTheRegisteredOutput(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($registry, $data->blockStateRegistry()),
            $items,
        );
        $crafting = CraftingCatalog::fromData($data, $items, $registry, $projector);
        $recipe = null;
        foreach ($crafting->recipes()->all() as $candidate) {
            if (count($candidate->ingredients()) === 1 && count($candidate->outputs()) === 1) {
                $recipe = $candidate;
                break;
            }
        }
        self::assertNotNull($recipe);
        $ingredient = $recipe->ingredients()[0];
        $output = $recipe->outputs()[0];
        $networkId = $crafting->recipes()->networkId($recipe->identifier());
        self::assertNotNull($networkId);
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity-one', 'One'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState(
                    $ingredient->identifiers[0],
                    $ingredient->count,
                )),
            ], 0),
            1,
            1,
        );
        $world = new WorldSimulation(
            blockPalette: $palette,
            itemCatalog: $items,
            blockStateRegistry: $registry,
            craftingCatalog: $crafting,
        );
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join(
            'one',
            'identity-one',
            'One',
            bootstrap: $bootstrap,
        )));
        $world->tick();

        self::assertTrue($world->enqueue($factory->inventoryStackRequest('one', -1, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::CraftingInput, 0, 0),
                $ingredient->count,
            ),
        ])));
        $moved = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $moved);
        self::assertTrue($moved->success, $moved->reason);
        $input = $moved->craftingInventory[0] ?? null;
        self::assertNotNull($input);

        self::assertTrue($world->enqueue($factory->inventoryStackRequest(
            'one',
            -2,
            [
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Consume,
                    new InventorySlotReference(InventoryContainer::CraftingInput, 0, $input->stackNetworkId),
                    new InventorySlotReference(InventoryContainer::CraftingInput, 0, $input->stackNetworkId),
                    $ingredient->count,
                ),
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Take,
                    new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -2),
                    new InventorySlotReference(InventoryContainer::Main, 0, 0),
                    $output->count,
                ),
            ],
            crafting: new CraftingRequest($networkId, 1),
        )));
        $crafted = $world->tick()->events[0];

        self::assertInstanceOf(InventoryStackRequestProcessed::class, $crafted);
        self::assertTrue($crafted->success, $crafted->reason);
        self::assertNull($crafted->craftingInventory[0]);
        $craftedStack = $crafted->mainInventory[0];
        self::assertNotNull($craftedStack);
        self::assertSame($output->identifier, $craftedStack->identifier);
        self::assertSame($output->count, $craftedStack->count);
    }

    public function testAutomaticCraftingConsumesOnlyTheClaimedAuthoritativeMainInventoryIngredients(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($registry, $data->blockStateRegistry()),
            $items,
        );
        $crafting = CraftingCatalog::fromData($data, $items, $registry, $projector);
        $recipe = null;
        foreach ($crafting->recipes()->all() as $candidate) {
            if (count($candidate->ingredients()) === 1 && count($candidate->outputs()) === 1) {
                $recipe = $candidate;
                break;
            }
        }
        self::assertNotNull($recipe);
        $ingredient = $recipe->ingredients()[0];
        $output = $recipe->outputs()[0];
        $networkId = $crafting->recipes()->networkId($recipe->identifier());
        self::assertNotNull($networkId);
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity-one', 'One'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState(
                    $ingredient->identifiers[0],
                    $ingredient->count,
                )),
            ], 0),
            1,
            1,
        );
        $world = new WorldSimulation(
            blockPalette: $palette,
            itemCatalog: $items,
            blockStateRegistry: $registry,
            craftingCatalog: $crafting,
        );
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join(
            'one',
            'identity-one',
            'One',
            bootstrap: $bootstrap,
        )));
        $world->tick();

        self::assertTrue($world->enqueue($factory->inventoryStackRequest('one', -1, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Consume,
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                $ingredient->count,
            ),
        ])));
        $manual = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $manual);
        self::assertFalse($manual->success);
        self::assertSame('crafting_consume', $manual->reason);
        self::assertSame($ingredient->count, $manual->mainInventory[0]?->count);

        self::assertTrue($world->enqueue($factory->inventoryStackRequest(
            'one',
            -2,
            [
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Consume,
                    new InventorySlotReference(InventoryContainer::Main, 0, 1),
                    new InventorySlotReference(InventoryContainer::Main, 0, 1),
                    $ingredient->count,
                ),
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Take,
                    new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -2),
                    new InventorySlotReference(InventoryContainer::Main, 0, 0),
                    $output->count,
                ),
            ],
            crafting: new CraftingRequest($networkId, 1, automatic: true),
        )));
        $crafted = $world->tick()->events[0];

        self::assertInstanceOf(InventoryStackRequestProcessed::class, $crafted);
        self::assertTrue($crafted->success, $crafted->reason);
        self::assertSame(array_fill(0, 4, null), $crafted->craftingInventory);
        $craftedStack = $crafted->mainInventory[0];
        self::assertNotNull($craftedStack);
        self::assertSame($output->identifier, $craftedStack->identifier);
        self::assertSame($output->count, $craftedStack->count);
    }

    public function testComplexRepairCraftCommitsOnlyItsAuthoritativeDerivedResult(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($registry, $data->blockStateRegistry()),
            $items,
        );
        $crafting = CraftingCatalog::fromData($data, $items, $registry, $projector);
        $networkId = null;
        foreach ($crafting->protocolRecipes() as $recipe) {
            if ($recipe instanceof MultiCraftingRecipe
                && $recipe->uuid === ComplexCraftingRecipeEvaluator::REPAIR_ITEM) {
                $networkId = $recipe->recipeNetworkId;
                break;
            }
        }
        self::assertNotNull($networkId);

        $world = new WorldSimulation(
            blockPalette: $palette,
            itemCatalog: $items,
            blockStateRegistry: $registry,
            craftingCatalog: $crafting,
        );
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join(
            'one',
            'identity-one',
            'One',
            bootstrap: new PlayerBootstrap(
                new PlayerIdentity('identity-one', 'One'),
                'world',
                new Position(0.0, 64.0, 0.0),
                0.0,
                0.0,
                new PlayerInventoryState([
                    new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:iron_pickaxe', 1, 200)),
                    new PlayerInventoryEntry(1, new PlayerInventoryStackState('minecraft:iron_pickaxe', 1, 100)),
                ], 0),
                1,
                1,
            ),
        )));
        $world->tick();

        self::assertTrue($world->enqueue($factory->inventoryStackRequest('one', -1, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::CraftingInput, 0, 0),
                1,
            ),
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 1, 2),
                new InventorySlotReference(InventoryContainer::CraftingInput, 1, 0),
                1,
            ),
        ])));
        $moved = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $moved);
        self::assertTrue($moved->success, $moved->reason);
        $left = $moved->craftingInventory[0] ?? null;
        $right = $moved->craftingInventory[1] ?? null;
        self::assertNotNull($left);
        self::assertNotNull($right);

        self::assertTrue($world->enqueue($factory->inventoryStackRequest(
            'one',
            -2,
            [
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Consume,
                    new InventorySlotReference(InventoryContainer::CraftingInput, 0, $left->stackNetworkId),
                    new InventorySlotReference(InventoryContainer::CraftingInput, 0, $left->stackNetworkId),
                    1,
                ),
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Consume,
                    new InventorySlotReference(InventoryContainer::CraftingInput, 1, $right->stackNetworkId),
                    new InventorySlotReference(InventoryContainer::CraftingInput, 1, $right->stackNetworkId),
                    1,
                ),
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Take,
                    new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -2),
                    new InventorySlotReference(InventoryContainer::Main, 0, 0),
                    1,
                ),
            ],
            crafting: new CraftingRequest($networkId, 1),
        )));
        $crafted = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $crafted);
        self::assertTrue($crafted->success, $crafted->reason);
        $result = $crafted->mainInventory[0];
        self::assertNotNull($result);
        self::assertSame('minecraft:iron_pickaxe', $result->identifier);
        self::assertSame(37, $result->damage);
        self::assertSame(array_fill(0, 4, null), $crafted->craftingInventory);
    }

    public function testCraftingTableOpenAndCloseUseAThreeByThreeGrid(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(new WorldMetadata('crafting-table-test', 0), new FlatWorldGenerator($palette), new ChunkRepository(4));
        $table = null;
        foreach ($registry->states() as $state) {
            if ($state->identifier() === 'minecraft:crafting_table') {
                $table = $registry->internalId($state);
                break;
            }
        }
        self::assertNotNull($table);
        $blocks->setBlockState(1, 64, 0, $table);
        $world = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $palette,
            blockCatalog: BlockCatalog::vanilla($registry),
            blockStateRegistry: $registry,
        );
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(1, 64, 0),
            1,
            0,
            0,
            0.5,
            0.5,
            0.5,
        )));

        $opened = $world->tick()->events[0];
        self::assertInstanceOf(CraftingTableOpened::class, $opened);
        self::assertTrue($world->enqueue($factory->closeCraftingGrid('one')));
        $closed = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $closed);
        self::assertTrue($closed->success);
        self::assertFalse($closed->fullSync);
        self::assertCount(9, $closed->craftingInventory);
        self::assertSame(array_fill(0, 9, null), $closed->craftingInventory);
        self::assertSame(range(32, 40), array_map(
            static fn(InventorySlotReference $slot): int => $slot->responseSlotId(),
            array_values(array_filter(
                $closed->affectedSlots,
                static fn(InventorySlotReference $slot): bool => $slot->container === InventoryContainer::CraftingInput,
            )),
        ));
    }

    public function testDeathReturnsCraftingInputsToTheAuthoritativeInventory(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new WorldSimulation(blockPalette: $palette);
        $factory = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity-one', 'One'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:oak_planks', 1)),
            ], 0),
            1,
            1,
        );
        self::assertTrue($world->enqueue($factory->join(
            'one',
            'identity-one',
            'One',
            bootstrap: $bootstrap,
        )));
        $world->tick();
        self::assertTrue($world->enqueue($factory->inventoryStackRequest('one', -1, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::CraftingInput, 0, 0),
                1,
            ),
        ])));
        $world->tick();

        self::assertTrue($world->enqueue($factory->damage('one', 20.0)));
        $world->tick();
        self::assertTrue($world->enqueue($factory->respawn('one')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->syncInventory('one')));
        $synced = $world->tick()->events[0];

        self::assertInstanceOf(InventoryStackRequestProcessed::class, $synced);
        $restored = $synced->mainInventory[0];
        self::assertNotNull($restored);
        self::assertSame('minecraft:oak_planks', $restored->identifier);
        self::assertSame(1, $restored->count);
        self::assertSame(array_fill(0, 4, null), $synced->craftingInventory);
    }

    public function testDisconnectDropsCraftingInputsWhenTheMainInventoryIsFull(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $items = new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $world = new WorldSimulation(blockPalette: $palette, itemEntities: $items);
        $factory = new SimulationCommandFactory();
        $entries = [new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:oak_planks', 1))];
        for ($slot = 1; $slot < 36; ++$slot) {
            $entries[] = new PlayerInventoryEntry($slot, new PlayerInventoryStackState('minecraft:stone', 64));
        }
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity-one', 'One'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState($entries, 0),
            1,
            1,
        );
        self::assertTrue($world->enqueue($factory->join(
            'one',
            'identity-one',
            'One',
            bootstrap: $bootstrap,
        )));
        $world->tick();
        self::assertTrue($world->enqueue($factory->inventoryStackRequest('one', -1, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::CraftingInput, 0, 0),
                1,
            ),
        ])));
        $world->tick();
        self::assertTrue($world->enqueue($factory->pluginInventorySlot(
            'one',
            0,
            new InventoryStack('minecraft:grass_block', 64, 1, $palette->grassBlock),
        )));
        $slotChange = $world->tick()->events[0];
        if ($slotChange instanceof CommandRejected) {
            self::fail('Plugin inventory replacement was rejected: ' . $slotChange->reason);
        }
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $slotChange);

        self::assertTrue($world->enqueue($factory->disconnect('one')));
        $events = $world->tick()->events;

        self::assertCount(1, $items->all());
        self::assertSame('minecraft:oak_planks', $items->all()[0]->stack->identifier);
        self::assertSame(1, $items->all()[0]->stack->count);
        self::assertCount(1, array_values(array_filter(
            $events,
            static fn($event): bool => $event instanceof PlayerDisconnected,
        )));
    }

    public function testMiningEmitsBlockTexturedPunchEffectsAtBoundedIntervals(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(new WorldMetadata('punch-test', 0), new FlatWorldGenerator($palette), new ChunkRepository(4));
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();

        $position = new BlockPosition(1, 63, 0);
        self::assertTrue($world->enqueue($factory->breakBlock('one', 1, BlockBreakAction::Start, $position, 1)));
        $started = $world->tick()->events;
        $punches = array_values(array_filter($started, static fn($event): bool => $event instanceof BlockPunch));
        self::assertCount(1, $punches);
        self::assertSame($palette->grassBlock->value, $punches[0]->state->value);
        self::assertSame(1, $punches[0]->face);

        for ($tick = 0; $tick < 4; ++$tick) {
            self::assertSame([], array_values(array_filter(
                $world->tick()->events,
                static fn($event): bool => $event instanceof BlockPunch,
            )));
        }
        self::assertCount(1, array_values(array_filter(
            $world->tick()->events,
            static fn($event): bool => $event instanceof BlockPunch,
        )));
        self::assertTrue($world->enqueue($factory->breakBlock('one', 2, BlockBreakAction::Abort, null, 0)));
        $world->tick();
        for ($tick = 0; $tick < 5; ++$tick) {
            self::assertSame([], array_values(array_filter(
                $world->tick()->events,
                static fn($event): bool => $event instanceof BlockPunch,
            )));
        }
    }

    public function testGrassBreakRevalidatesAuthoritativeWorldWhenTheClientPredictsCompletion(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('break-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        self::assertTrue($world->enqueue($factory->join('two', 'identity-two', 'Two')));
        $world->tick();
        $position = new BlockPosition(1, 63, 0);

        self::assertTrue($world->enqueue($factory->breakBlock('one', 1, BlockBreakAction::Start, $position, 1)));
        $started = $world->tick()->events[0];
        self::assertInstanceOf(BlockBreakStarted::class, $started);
        self::assertSame(3640, $started->breakRate);
        self::assertSame(['one', 'two'], $started->recipients());

        self::assertTrue($world->enqueue($factory->breakBlock('one', 2, BlockBreakAction::Complete, $position, 1)));
        $changed = $world->tick()->events[0];
        self::assertInstanceOf(BlockChanged::class, $changed);
        self::assertSame(['one', 'two'], $changed->recipients());
        self::assertSame($palette->air->value, $changed->state->value);
        self::assertSame($palette->air->value, $blocks->blockStateAt(1, 63, 0)->value);

        $withoutStart = new BlockPosition(2, 63, 0);
        self::assertTrue($world->enqueue($factory->breakBlock('one', 3, BlockBreakAction::Complete, $withoutStart, 1)));
        $predicted = $world->tick()->events[0];
        self::assertInstanceOf(BlockChanged::class, $predicted);
        self::assertFalse($predicted->stopBreaking);
        self::assertSame($palette->air->value, $predicted->state->value);
        self::assertSame($palette->air->value, $blocks->blockStateAt(2, 63, 0)->value);
    }

    public function testConcurrentBreakCompletionCorrectsThePlayerWhoLostTheRace(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('break-race-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        self::assertTrue($world->enqueue($factory->join('two', 'identity-two', 'Two')));
        $world->tick();
        $position = new BlockPosition(1, 63, 0);
        self::assertTrue($world->enqueue($factory->breakBlock('one', 1, BlockBreakAction::Start, $position, 1)));
        self::assertTrue($world->enqueue($factory->breakBlock('two', 1, BlockBreakAction::Start, $position, 1)));
        $started = $world->tick()->events;
        self::assertCount(6, $started);
        self::assertInstanceOf(BlockBreakStarted::class, $started[0]);
        self::assertInstanceOf(BlockPunch::class, $started[1]);
        self::assertInstanceOf(ArmSwung::class, $started[2]);
        self::assertInstanceOf(BlockBreakStarted::class, $started[3]);
        self::assertInstanceOf(BlockPunch::class, $started[4]);
        self::assertInstanceOf(ArmSwung::class, $started[5]);
        for ($tick = 0; $tick < 17; ++$tick) {
            $world->tick();
        }

        self::assertTrue($world->enqueue($factory->breakBlock('one', 2, BlockBreakAction::Complete, $position, 1)));
        self::assertTrue($world->enqueue($factory->breakBlock('two', 2, BlockBreakAction::Complete, $position, 1)));
        $events = $world->tick()->events;
        self::assertCount(3, $events);
        self::assertInstanceOf(BlockChanged::class, $events[0]);
        self::assertSame(['one', 'two'], $events[0]->recipients());
        self::assertSame($palette->air->value, $events[0]->state->value);
        self::assertInstanceOf(ArmSwung::class, $events[1]);
        self::assertInstanceOf(BlockChanged::class, $events[2]);
        self::assertSame(['two'], $events[2]->recipients());
        self::assertSame($palette->air->value, $events[2]->state->value);
    }

    public function testBlockOverrideExhaustionCorrectsOnlyTheOwnerWithoutCrashingTheWorld(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('break-capacity-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
            overrides: new BlockOverrideStore(1),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();

        foreach ([new BlockPosition(1, 63, 0), new BlockPosition(2, 63, 0)] as $index => $position) {
            $sequence = ($index * 2) + 1;
            self::assertTrue($world->enqueue($factory->breakBlock('one', $sequence, BlockBreakAction::Start, $position, 1)));
            $world->tick();
            for ($tick = 0; $tick < 17; ++$tick) {
                $world->tick();
            }
            self::assertTrue($world->enqueue($factory->breakBlock('one', $sequence + 1, BlockBreakAction::Complete, $position, 1)));
            $events = $world->tick()->events;
            self::assertCount(2, $events);
            self::assertInstanceOf(BlockChanged::class, $events[0]);
            self::assertInstanceOf(ArmSwung::class, $events[1]);
        }

        self::assertSame($palette->air->value, $blocks->blockStateAt(1, 63, 0)->value);
        self::assertSame($palette->grassBlock->value, $blocks->blockStateAt(2, 63, 0)->value);
    }

    public function testGrassPlacementUsesAuthoritativeServerInventoryAndDecrementsOnlyTheOwnersStack(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('placement-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        self::assertTrue($world->enqueue($factory->join('two', 'identity-two', 'Two')));
        $world->tick();
        $clicked = new BlockPosition(1, 63, 0);
        $breaking = new BlockPosition(3, 63, 0);
        self::assertTrue($world->enqueue($factory->breakBlock('one', 1, BlockBreakAction::Start, $breaking, 1)));
        $started = $world->tick()->events[0];
        self::assertInstanceOf(BlockBreakStarted::class, $started);

        self::assertTrue($world->enqueue($factory->placeBlock(
            'one',
            1,
            $clicked,
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $event = $world->tick()->events[0];
        self::assertInstanceOf(BlockPlaced::class, $event);
        self::assertSame(['one', 'two'], $event->recipients());
        self::assertSame([1, 64, 0], [$event->position->x, $event->position->y, $event->position->z]);
        self::assertSame(63, $event->remainingStack?->count);
        self::assertEquals($breaking, $event->stoppedBreakingPosition);
        self::assertSame($palette->grassBlock->value, $blocks->blockStateAt(1, 64, 0)->value);

        self::assertTrue($world->enqueue($factory->placeBlock(
            'two',
            1,
            new BlockPosition(2, 63, 0),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $second = $world->tick()->events[0];
        self::assertInstanceOf(BlockPlaced::class, $second);
        self::assertSame(63, $second->remainingStack?->count);
    }

    public function testObtainedCobblestoneAndCobbledDeepslateCanBePlacedInSurvival(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('obtained-block-placement-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $palette,
            itemCatalog: ItemCatalog::vanilla($data->itemNetworkRegistry()),
            blockCatalog: BlockCatalog::vanilla(),
            blockStateRegistry: $registry,
        );
        $factory = new SimulationCommandFactory();
        $world->enqueue($factory->join('one', 'identity-one', 'One'));
        $world->tick();

        foreach ([
            ['minecraft:cobblestone', VanillaBlockStates::cobblestone(), 1],
            ['minecraft:cobbled_deepslate', VanillaBlockStates::cobbledDeepslate(), 2],
        ] as [$identifier, $state, $sequence]) {
            $world->enqueue($factory->giveItem('one', $identifier, 1));
            $world->tick();
            $world->enqueue($factory->selectHotbarSlot('one', 1));
            $world->tick();
            $world->enqueue($factory->placeBlock(
                'one',
                $sequence,
                new BlockPosition($sequence + 1, 63, 0),
                1,
                1,
                0,
                0.5,
                1.0,
                0.5,
            ));
            $placed = $world->tick()->events[0];
            self::assertInstanceOf(BlockPlaced::class, $placed, $placed instanceof BlockPlacementCorrected ? $placed->reason : get_debug_type($placed));
            self::assertSame($registry->internalId($state)->value, $blocks->blockStateAt($sequence + 1, 64, 0)->value);
            self::assertNull($placed->remainingStack);
        }
    }

    public function testPlacementRejectsCollisionAndRepairsBothBlocksAndInventory(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('placement-collision-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(0, 63, 0),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $event = $world->tick()->events[0];
        self::assertInstanceOf(BlockPlacementCorrected::class, $event);
        self::assertSame('collision', $event->reason);
        self::assertSame(['one'], $event->recipients());
        self::assertSame($palette->grassBlock->value, $event->clickedState->value);
        self::assertSame($palette->air->value, $event->placedState->value);
        self::assertSame(64, $event->heldStack?->count);
        self::assertSame($palette->air->value, $blocks->blockStateAt(0, 64, 0)->value);
    }

    public function testPlacementUsesTheCanonicalOffsetForEveryBlockFace(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('placement-faces-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();
        $cases = [
            [0, new BlockPosition(2, 65, 0), [2, 64, 0]],
            [1, new BlockPosition(2, 63, 2), [2, 64, 2]],
            [2, new BlockPosition(-2, 64, 2), [-2, 64, 1]],
            [3, new BlockPosition(-2, 64, -2), [-2, 64, -1]],
            [4, new BlockPosition(2, 64, -2), [1, 64, -2]],
            [5, new BlockPosition(-3, 65, 0), [-2, 65, 0]],
        ];
        foreach ($cases as [, $clicked]) {
            $blocks->setBlockState($clicked->x, $clicked->y, $clicked->z, $palette->grassBlock);
        }

        foreach ($cases as $index => [$face, $clicked, $expected]) {
            self::assertTrue($world->enqueue($factory->placeBlock(
                'one',
                $index + 1,
                $clicked,
                $face,
                0,
                0,
                0.5,
                0.5,
                0.5,
            )));
            $event = $world->tick()->events[0];
            self::assertInstanceOf(BlockPlaced::class, $event);
            self::assertSame($expected, [$event->position->x, $event->position->y, $event->position->z]);
            self::assertSame(63 - $index, $event->remainingStack?->count);
        }
    }

    public function testPillarPlacementUsesTheClickedFaceAndAuthoritativeHeldItem(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('pillar-placement-faces-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $blockCatalog = BlockCatalog::vanilla($registry);
        $world = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $palette,
            itemCatalog: ItemCatalog::vanilla($data->itemNetworkRegistry(), $blockCatalog),
            blockCatalog: $blockCatalog,
            blockStateRegistry: $registry,
        );
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->giveItem('one', 'minecraft:oak_log', 6)));
        $world->tick();
        self::assertTrue($world->enqueue($factory->selectHotbarSlot('one', 1)));
        $world->tick();
        $cases = [
            [0, 'y', new BlockPosition(2, 65, 0)],
            [1, 'y', new BlockPosition(2, 63, 2)],
            [2, 'z', new BlockPosition(-2, 64, 2)],
            [3, 'z', new BlockPosition(-2, 64, -2)],
            [4, 'x', new BlockPosition(2, 64, -2)],
            [5, 'x', new BlockPosition(-3, 65, 0)],
        ];
        foreach ($cases as [, , $clicked]) {
            $blocks->setBlockState($clicked->x, $clicked->y, $clicked->z, $palette->grassBlock);
        }

        foreach ($cases as $index => [$face, $expectedAxis, $clicked]) {
            self::assertTrue($world->enqueue($factory->placeBlock(
                'one',
                $index + 1,
                $clicked,
                $face,
                1,
                0,
                0.5,
                0.5,
                0.5,
            )));
            $event = $world->tick()->events[0];
            self::assertInstanceOf(BlockPlaced::class, $event);
            $placed = $registry->state($event->state);
            self::assertSame('minecraft:oak_log', $placed->identifier());
            self::assertSame($expectedAxis, $placed->properties()['pillar_axis'] ?? null);
            self::assertSame(5 - $index ?: null, $event->remainingStack?->count);
        }
    }

    public function testPlacementCapacityFailureDoesNotConsumeTheHeldStack(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('placement-capacity-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
            overrides: new BlockOverrideStore(1),
        );
        $blocks->setBlockState(3, 63, 0, $palette->air);
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(2, 63, 0),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));

        $event = $world->tick()->events[0];
        self::assertInstanceOf(BlockPlacementCorrected::class, $event);
        self::assertSame('capacity', $event->reason);
        self::assertSame(64, $event->heldStack?->count);
        self::assertSame($palette->air->value, $blocks->blockStateAt(2, 64, 0)->value);
    }

    public function testPerfectClientPredictionCannotPlaceFromAnEmptyServerSlot(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('placement-empty-slot-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        self::assertTrue($world->enqueue($factory->selectHotbarSlot('one', 8)));
        $world->tick();

        self::assertTrue($world->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(2, 63, 0),
            1,
            8,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $event = $world->tick()->events[0];
        self::assertInstanceOf(BlockPlacementCorrected::class, $event);
        self::assertSame('empty_hand', $event->reason);
        self::assertNull($event->heldStack);
        self::assertSame($palette->air->value, $blocks->blockStateAt(2, 64, 0)->value);
    }

    public function testHotbarSelectionChangesOnlyAuthoritativeInventoryAndNotifiesPeers(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('selection-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        self::assertTrue($world->enqueue($factory->join('two', 'identity-two', 'Two')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->selectHotbarSlot('one', 8)));

        $event = $world->tick()->events[0];
        self::assertInstanceOf(HeldItemChanged::class, $event);
        self::assertSame(['two'], $event->recipients());
        self::assertSame(8, $event->hotbarSlot);
        self::assertNull($event->stack);
    }

    public function testInventoryRequestCommitsAtomicallyBeforeSelectingAndPlacingSplitStack(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('inventory-placement-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        self::assertTrue($world->enqueue($factory->join('two', 'identity-two', 'Two')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->inventoryStackRequest('one', -5, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
                32,
            ),
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Place,
                new InventorySlotReference(InventoryContainer::Cursor, 0, -5),
                new InventorySlotReference(InventoryContainer::Main, 1, 0),
                32,
            ),
        ])));
        $processed = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $processed);
        self::assertTrue($processed->success);
        self::assertSame(32, $processed->mainInventory[0]?->count);
        self::assertSame(32, $processed->mainInventory[1]?->count);
        self::assertNull($processed->cursorStack);
        self::assertTrue($processed->selectedStackChanged);
        self::assertSame(['two'], $processed->peerSessionIds);

        self::assertTrue($world->enqueue($factory->selectHotbarSlot('one', 1)));
        self::assertTrue($world->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(2, 63, 0),
            1,
            1,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $events = $world->tick()->events;
        self::assertInstanceOf(HeldItemChanged::class, $events[0]);
        self::assertSame(32, $events[0]->stack?->count);
        self::assertInstanceOf(BlockPlaced::class, $events[1]);
        self::assertSame(31, $events[1]->remainingStack?->count);
        self::assertSame($palette->grassBlock->value, $blocks->blockStateAt(2, 64, 0)->value);
    }

    public function testLegacyPredictedSplitCommitsBeforeSelectingAndUsingDestinationSlot(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('legacy-inventory-placement-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->inventoryStackRequest('one', 0, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 0, 1, expectedCount: 64),
                new InventorySlotReference(InventoryContainer::Main, 1, 0, expectedCount: 0),
                32,
            ),
        ], responseMode: InventoryResponseMode::LegacySlotSync)));
        $processed = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $processed);
        self::assertTrue($processed->success);
        self::assertSame(InventoryResponseMode::LegacySlotSync, $processed->responseMode);
        self::assertSame(32, $processed->mainInventory[0]?->count);
        self::assertSame(32, $processed->mainInventory[1]?->count);

        self::assertTrue($world->enqueue($factory->selectHotbarSlot('one', 1)));
        self::assertTrue($world->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(2, 63, 0),
            1,
            1,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $events = $world->tick()->events;
        self::assertInstanceOf(BlockPlaced::class, $events[1]);
        self::assertSame(31, $events[1]->remainingStack?->count);
    }

    public function testCreativeOutputIsAcceptedOnlyForAnAuthoritativeCreativePlayer(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockPalette: $palette);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();
        $action = new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -21),
            new InventorySlotReference(InventoryContainer::Main, 1, 0),
            16,
        );
        $creativeStack = new InventoryStack('minecraft:grass_block', 64, 1, $palette->grassBlock);

        self::assertTrue($world->enqueue($factory->inventoryStackRequest(
            'one',
            -21,
            [$action],
            authoritativeCreativeStack: $creativeStack,
        )));
        $rejected = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $rejected);
        self::assertFalse($rejected->success);
        self::assertSame('creative_requires_creative_mode', $rejected->reason);
        self::assertNull($rejected->mainInventory[1]);

        self::assertTrue($world->enqueue($factory->changeGameMode('one', GameMode::CREATIVE)));
        $world->tick();
        self::assertTrue($world->enqueue($factory->inventoryStackRequest(
            'one',
            -21,
            [$action],
            authoritativeCreativeStack: $creativeStack,
        )));
        $accepted = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $accepted);
        self::assertTrue($accepted->success);
        self::assertSame(16, $accepted->mainInventory[1]?->count);
    }

    public function testJoinPublishesDeterministicPeerVisibilityAndFixedSpawn(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('b-session', 'identity-b', 'Bravo')));
        self::assertTrue($world->enqueue($factory->join('a-session', 'identity-a', 'Alpha')));

        $tick = $world->tick();
        self::assertSame(1, $tick->number);
        self::assertSame(2, $tick->processedCommands);
        self::assertCount(2, $tick->events);
        $first = $tick->events[0];
        self::assertInstanceOf(PlayerJoined::class, $first);
        self::assertSame([], $first->existingPeers);
        self::assertSame(['b-session'], $first->recipients());
        self::assertSame(64.0, $first->player->position->y);
        self::assertSame(1, $first->player->runtimeActorId);

        $second = $tick->events[1];
        self::assertInstanceOf(PlayerJoined::class, $second);
        self::assertSame(['b-session'], array_map(static fn($peer): string => $peer->sessionId, $second->existingPeers));
        self::assertSame(['a-session', 'b-session'], $second->recipients());
        self::assertSame(2, $second->player->runtimeActorId);
        self::assertSame(['a-session', 'b-session'], array_map(static fn($player): string => $player->sessionId, $world->snapshot()->players));
    }

    public function testApprovedBootstrapBecomesTheExactAuthoritativeJoinState(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockPalette: $palette);
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('00000000-0000-0000-0000-000000000001', 'Player', '99'),
            'world',
            new Position(25.5, 70.25, -12.75),
            145.0,
            -30.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(4, new PlayerInventoryStackState('minecraft:grass_block', 12)),
            ], 4),
            100,
            200,
        );

        self::assertTrue($world->enqueue($factory->join(
            'session',
            $bootstrap->identity->uuid,
            $bootstrap->identity->displayName,
            7,
            $bootstrap,
            true,
        )));
        $joined = $world->tick()->events[0];

        self::assertInstanceOf(PlayerJoined::class, $joined);
        self::assertEquals($bootstrap->position, $joined->player->position);
        self::assertSame(145.0, $joined->player->yaw);
        self::assertSame(-30.0, $joined->player->pitch);
        self::assertSame(7, $joined->player->runtimeActorId);
        self::assertSame($bootstrap->identity->uuid, $joined->player->identity);
    }

    public function testExplicitRuntimeActorIdsAreUniqueAndSurviveDisconnectEvents(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One', 41)));
        self::assertTrue($world->enqueue($factory->join('two', 'identity-two', 'Two', 41)));

        $joined = $world->tick()->events;
        self::assertInstanceOf(PlayerJoined::class, $joined[0]);
        self::assertSame(41, $joined[0]->player->runtimeActorId);
        self::assertInstanceOf(CommandRejected::class, $joined[1]);
        self::assertSame('duplicate_actor_id', $joined[1]->reason);

        self::assertTrue($world->enqueue($factory->disconnect('one')));
        $left = $world->tick()->events[0];
        self::assertInstanceOf(PlayerDisconnected::class, $left);
        self::assertSame(41, $left->runtimeActorId);
        self::assertTrue($world->enqueue($factory->join('two', 'identity-two', 'Two', 41)));
        $rejoined = $world->tick()->events[0];
        self::assertInstanceOf(PlayerJoined::class, $rejoined);
        self::assertSame(41, $rejoined->player->runtimeActorId);
    }

    public function testDuplicateSessionIdentityAndCapacityAreRejectedWithoutGhosts(): void
    {
        $limits = new SimulationLimits(maximumPlayers: 1);
        $factory = new SimulationCommandFactory($limits);
        $world = new WorldSimulation($limits);
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        self::assertTrue($world->enqueue($factory->join('one', 'identity-two', 'Two')));
        self::assertTrue($world->enqueue($factory->join('two', 'identity-one', 'Two')));
        self::assertTrue($world->enqueue($factory->join('three', 'identity-three', 'Three')));

        $events = $world->tick()->events;
        $reasons = [];
        foreach (array_slice($events, 1) as $event) {
            self::assertInstanceOf(CommandRejected::class, $event);
            $reasons[] = $event->reason;
        }
        self::assertSame(['duplicate_session', 'duplicate_identity', 'world_full'], $reasons);
        self::assertCount(1, $world->snapshot()->players);
    }

    public function testImpossiblePredictedMovementIsCorrectedWithoutChangingState(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'session',
            1,
            1000.0,
            64.0,
            -1000.0,
            0.0,
            10.0,
            MovementMode::SPRINTING,
            clientTick: new ClientInputTick(0x80000000, 25),
        )));

        $events = $world->tick()->events;
        self::assertCount(1, $events);
        self::assertInstanceOf(MovementCorrected::class, $events[0]);
        self::assertSame('movement_rate', $events[0]->reason);
        self::assertInstanceOf(ClientInputTick::class, $events[0]->clientTick);
        self::assertSame(0x80000000, $events[0]->clientTick->high);
        self::assertSame(25, $events[0]->clientTick->low);
        self::assertSame(0.0, $world->snapshot()->players[0]->position->x);
        self::assertSame(1, $world->snapshot()->players[0]->movementSequence);

        self::assertTrue($world->enqueue($factory->move('session', 1, 0.0, 64.0, 0.0, 0.0, 0.0, MovementMode::STOPPED)));
        $staleEvents = $world->tick()->events;
        self::assertCount(1, $staleEvents);
        $stale = $staleEvents[0];
        self::assertInstanceOf(CommandRejected::class, $stale);
        self::assertSame('stale_sequence', $stale->reason);
    }

    public function testMovementSafetyBoundRejectsBeforeLoadingRemoteCollisionTerrain(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $repository = new ChunkRepository(16);
        $blocks = new World(
            new WorldMetadata('movement-query-bound-test', 0),
            new FlatWorldGenerator($palette),
            $repository,
        );
        self::retainOriginCollisionTerrain($blocks);
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        $loadedAtJoin = $repository->count();
        self::assertGreaterThan(0, $loadedAtJoin);

        $world->enqueue($factory->move(
            'session',
            1,
            1000.0,
            64.0,
            1000.0,
            0.0,
            0.0,
            MovementMode::SPRINTING,
        ));
        $event = $world->tick()->events[0];

        self::assertInstanceOf(MovementCorrected::class, $event);
        self::assertSame('movement_rate', $event->reason);
        self::assertSame($loadedAtJoin, $repository->count());
    }

    public function testWorldBackedMovementStopsAtCanonicalWallAndSlidesAlongIt(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('movement-wall-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $blocks->setBlockState(1, 64, 0, $palette->grassBlock);
        self::retainOriginCollisionTerrain($blocks);
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->enqueue($factory->join('peer', 'peer-identity', 'Peer'));
        $world->tick();
        $world->enqueue($factory->move(
            'session',
            1,
            2.0,
            64.0,
            1.0,
            0.0,
            0.0,
            MovementMode::WALKING,
        ));

        $event = $world->tick()->events[0];
        self::assertInstanceOf(MovementCorrected::class, $event);
        self::assertSame('terrain_collision', $event->reason);
        self::assertSame(['peer'], $event->peerSessionIds);
        self::assertEqualsWithDelta(0.7, $event->authoritativePlayer->position->x, 0.000001);
        self::assertEqualsWithDelta(1.0, $event->authoritativePlayer->position->z, 0.000001);
        self::assertSame(VerticalState::GROUNDED, $event->authoritativePlayer->verticalState);
    }

    public function testWorldBackedMovementFallsIntoAChangedTerrainCell(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('movement-hole-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $blocks->setBlockState(0, 63, 0, $palette->air);
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(
            spawn: new Position(0.5, 64.0, 0.5),
            blockWorld: $blocks,
            blockPalette: $palette,
        );
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $joined = $world->tick()->events[0];
        self::assertInstanceOf(PlayerJoined::class, $joined);
        self::assertSame(VerticalState::AIRBORNE, $joined->player->verticalState);

        $world->enqueue($factory->move(
            'session',
            1,
            0.5,
            62.0,
            0.5,
            0.0,
            0.0,
            MovementMode::WALKING,
            deltaY: -2.0,
        ));
        $event = $world->tick()->events[0];
        self::assertInstanceOf(MovementCorrected::class, $event);
        self::assertSame('terrain_collision', $event->reason);
        self::assertEqualsWithDelta(63.0, $event->authoritativePlayer->position->y, 0.000001);
        self::assertSame(VerticalState::GROUNDED, $event->authoritativePlayer->verticalState);
    }

    public function testBreakingSupportImmediatelyMarksPlayerAirborne(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('break-support-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(
            spawn: new Position(0.5, 64.0, 0.5),
            blockWorld: $blocks,
            blockPalette: $palette,
        );
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        $support = new BlockPosition(0, 63, 0);
        $world->enqueue($factory->breakBlock('session', 1, BlockBreakAction::Start, $support, 1));
        $world->tick();
        $world->enqueue($factory->breakBlock('session', 2, BlockBreakAction::Complete, $support, 1));
        $world->tick();

        self::assertSame(VerticalState::AIRBORNE, $world->snapshot()->players[0]->verticalState);
    }

    public function testPlacingSupportImmediatelyMarksTouchingPlayerGrounded(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(
            new WorldMetadata('place-support-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(
            spawn: new Position(0.5, 65.0, 0.5),
            blockWorld: $blocks,
            blockPalette: $palette,
        );
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        self::assertSame(VerticalState::AIRBORNE, $world->snapshot()->players[0]->verticalState);
        $world->enqueue($factory->placeBlock(
            'session',
            1,
            new BlockPosition(0, 63, 0),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        ));
        $event = $world->tick()->events[0];

        self::assertInstanceOf(BlockPlaced::class, $event);
        self::assertSame(VerticalState::GROUNDED, $world->snapshot()->players[0]->verticalState);
    }

    public function testAcceptedMovementIsPublishedOnlyToPeers(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('sender', 'identity-sender', 'Sender')));
        self::assertTrue($world->enqueue($factory->join('peer', 'identity-peer', 'Peer')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'sender',
            1,
            1.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
            deltaX: 1.0,
        )));

        $event = $world->tick()->events[0];
        self::assertInstanceOf(PlayerMoved::class, $event);
        self::assertSame(['peer'], $event->recipients());
    }

    public function testAcceptedSprintDistanceAppliesAuthoritativeExhaustion(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('00000000-0000-0000-0000-000000000001', 'Runner'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([], 0),
            1,
            1,
            saturation: 20.0,
            exhaustion: 3.95,
        );
        self::assertTrue($world->enqueue($factory->join(
            'runner',
            $bootstrap->identity->uuid,
            $bootstrap->identity->displayName,
            bootstrap: $bootstrap,
            loginApproved: true,
        )));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'runner',
            1,
            1.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::SPRINTING,
            deltaX: 1.0,
            sprinting: true,
        )));

        $events = $world->tick()->events;
        self::assertCount(2, $events);
        self::assertInstanceOf(PlayerMoved::class, $events[0]);
        self::assertInstanceOf(NutritionChanged::class, $events[1]);
        self::assertSame(NutritionChangeReason::EXHAUSTION, $events[1]->reason);
        self::assertSame(19.0, $events[1]->player->saturation);
        self::assertEqualsWithDelta(0.05, $events[1]->player->exhaustion, 0.000001);
    }

    public function testFractionalSprintExhaustionDoesNotCreateAClientProjectionEvent(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('runner', 'identity-runner', 'Runner')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'runner',
            1,
            1.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::SPRINTING,
            deltaX: 1.0,
            sprinting: true,
        )));

        $events = $world->tick()->events;
        self::assertCount(1, $events);
        self::assertInstanceOf(PlayerMoved::class, $events[0]);
        self::assertGreaterThan(0.0, $events[0]->player->exhaustion);
        self::assertSame(20.0, $events[0]->player->food);
        self::assertSame(20.0, $events[0]->player->saturation);
    }

    public function testMovementRetainsHeadYawAndReportsOnlyPostureTransitions(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('sender', 'identity-sender', 'Sender')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->move(
            'sender',
            1,
            0.0,
            64.0,
            0.0,
            20.0,
            0.0,
            MovementMode::CROUCHING,
            headYaw: 35.0,
            sneaking: true,
            sprinting: false,
        )));
        $startedSneaking = $world->tick()->events[0];
        self::assertInstanceOf(PlayerMoved::class, $startedSneaking);
        self::assertTrue($startedSneaking->postureChanged);
        self::assertSame(35.0, $startedSneaking->player->headYaw);
        self::assertTrue($startedSneaking->player->sneaking);
        self::assertFalse($startedSneaking->player->sprinting);

        self::assertTrue($world->enqueue($factory->move(
            'sender',
            2,
            0.0,
            64.0,
            0.0,
            21.0,
            0.0,
            MovementMode::JUMPING,
            headYaw: 36.0,
            sneaking: true,
            sprinting: false,
        )));
        $samePosture = $world->tick()->events[0];
        self::assertInstanceOf(PlayerMoved::class, $samePosture);
        self::assertFalse($samePosture->postureChanged);
        self::assertTrue($samePosture->player->sneaking);

        self::assertTrue($world->enqueue($factory->move(
            'sender',
            3,
            0.0,
            64.0,
            0.0,
            22.0,
            0.0,
            MovementMode::SPRINTING,
            headYaw: 37.0,
            sneaking: false,
            sprinting: true,
        )));
        $startedSprinting = $world->tick()->events[0];
        self::assertInstanceOf(PlayerMoved::class, $startedSprinting);
        self::assertTrue($startedSprinting->postureChanged);
        self::assertFalse($startedSprinting->player->sneaking);
        self::assertTrue($startedSprinting->player->sprinting);
    }

    public function testRotationOnlyPredictedFramesReachPeers(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('sender', 'identity-sender', 'Sender'));
        $world->enqueue($factory->join('peer', 'identity-peer', 'Peer'));
        $world->tick();
        $world->enqueue($factory->move(
            'sender',
            1,
            0.0,
            64.0,
            0.0,
            90.0,
            0.0,
            MovementMode::WALKING,
        ));

        $event = $world->tick()->events[0];
        self::assertInstanceOf(PlayerMoved::class, $event);
        self::assertSame(0.0, $event->player->position->x);
        self::assertSame(90.0, $event->player->yaw);
        self::assertSame(['peer'], $event->recipients());

        $world->enqueue($factory->move(
            'sender',
            2,
            0.0,
            64.0,
            0.0,
            180.0,
            15.0,
            MovementMode::STOPPED,
        ));
        $rotation = $world->tick()->events[0];
        self::assertInstanceOf(PlayerMoved::class, $rotation);
        self::assertSame(180.0, $rotation->player->yaw);
        self::assertSame(15.0, $rotation->player->pitch);
    }

    public function testMovementCreditIsCappedAfterIdleTicks(): void
    {
        $limits = new SimulationLimits(maximumMovementPerTick: 2.0, maximumMovementCreditTicks: 3);
        $factory = new SimulationCommandFactory($limits);
        $world = new WorldSimulation($limits);
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        for ($i = 0; $i < 20; ++$i) {
            $world->tick();
        }
        $world->enqueue($factory->move('session', 1, 7.0, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING));
        self::assertInstanceOf(MovementCorrected::class, $world->tick()->events[0]);
    }

    public function testNormalClientPredictionDoesNotCauseCorrectionFlood(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        for ($sequence = 1; $sequence <= 20; ++$sequence) {
            $world->enqueue($factory->move(
                'session',
                $sequence,
                $sequence * 0.3,
                64.0,
                0.0,
                0.0,
                0.0,
                MovementMode::WALKING,
            ));
            $events = $world->tick()->events;
            self::assertCount(1, $events);
            self::assertInstanceOf(PlayerMoved::class, $events[0]);
        }
        self::assertEqualsWithDelta(6.0, $world->snapshot()->players[0]->position->x, 0.000001);
    }

    public function testJumpAndLandingTransitionsComeFromValidatedPredictedFrames(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        $world->enqueue($factory->move(
            'session',
            1,
            0.0,
            64.42,
            0.0,
            0.0,
            0.0,
            MovementMode::JUMPING,
            deltaY: 0.34,
            jumpRequested: true,
        ));
        $world->tick();
        $jumped = $world->snapshot()->players[0];
        self::assertSame(VerticalState::AIRBORNE, $jumped->verticalState);
        self::assertEqualsWithDelta(64.42, $jumped->position->y, 0.000001);
        self::assertEqualsWithDelta(0.42, $jumped->verticalVelocity, 0.000001);

        $world->tick();
        self::assertEqualsWithDelta(64.42, $world->snapshot()->players[0]->position->y, 0.000001);
        $world->enqueue($factory->move(
            'session',
            2,
            0.0,
            64.0005,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -0.4,
        ));
        $world->tick();
        $landed = $world->snapshot()->players[0];
        self::assertSame(VerticalState::GROUNDED, $landed->verticalState);
        self::assertSame(64.0, $landed->position->y);
        self::assertSame(0.0, $landed->verticalVelocity);
    }

    public function testBelowFloorAndUngroundedJumpFramesAreCorrected(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();

        $world->enqueue($factory->move('session', 1, 0.0, 63.9, 0.0, 0.0, 0.0, MovementMode::WALKING, deltaY: -0.1));
        $below = $world->tick()->events[0];
        self::assertInstanceOf(MovementCorrected::class, $below);
        self::assertSame('terrain_collision', $below->reason);
        self::assertSame(64.0, $below->authoritativePlayer->position->y);

        $world->enqueue($factory->move('session', 2, 0.0, 64.2, 0.0, 0.0, 0.0, MovementMode::WALKING, deltaY: 0.2));
        $unannounced = $world->tick()->events[0];
        self::assertInstanceOf(MovementCorrected::class, $unannounced);
        self::assertSame('jump_required', $unannounced->reason);
        self::assertSame(64.0, $unannounced->authoritativePlayer->position->y);
    }

    public function testGroundedJumpEdgeAuthorizesTheFollowingUpwardFrame(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        $world->enqueue($factory->move(
            'session',
            1,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::JUMPING,
            jumpRequested: true,
        ));
        self::assertInstanceOf(PlayerMoved::class, $world->tick()->events[0]);

        $world->enqueue($factory->move(
            'session',
            2,
            0.0,
            64.3,
            0.0,
            0.0,
            0.0,
            MovementMode::JUMPING,
            deltaY: 0.3,
        ));
        $upward = $world->tick()->events[0];
        self::assertInstanceOf(PlayerMoved::class, $upward);
        self::assertSame(VerticalState::AIRBORNE, $upward->player->verticalState);
        self::assertEqualsWithDelta(64.3, $upward->player->position->y, 0.000001);
    }

    public function testAirborneJumpInputCannotResetVerticalVelocity(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        $world->enqueue($factory->move('session', 1, 0.0, 64.42, 0.0, 0.0, 0.0, MovementMode::JUMPING, deltaY: 0.34, jumpRequested: true));
        $world->tick();
        $world->enqueue($factory->move('session', 2, 0.0, 64.76, 0.0, 0.0, 0.0, MovementMode::JUMPING, deltaY: 0.26, jumpRequested: true));
        $world->tick();
        $player = $world->snapshot()->players[0];
        self::assertEqualsWithDelta(64.76, $player->position->y, 0.000001);
        self::assertEqualsWithDelta(0.34, $player->verticalVelocity, 0.000001);
    }

    public function testFallingPlayerLandsWithoutPenetratingFlatTerrain(): void
    {
        $world = new WorldSimulation(spawn: new Position(0.0, 66.0, 0.0));
        $world->enqueue((new SimulationCommandFactory())->join('session', 'identity', 'Player'));
        $world->tick();
        self::assertSame(66.0, $world->snapshot()->players[0]->position->y, 'No client frame means no invented gravity step.');
        $world->enqueue((new SimulationCommandFactory())->move(
            'session',
            1,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::STOPPED,
            deltaY: -2.0,
        ));
        $world->tick();
        $player = $world->snapshot()->players[0];
        self::assertSame(VerticalState::GROUNDED, $player->verticalState);
        self::assertSame(64.0, $player->position->y);
        self::assertSame(0.0, $player->verticalVelocity);
    }

    public function testMovementQueueCoalescesLatestInputAndLatchesJumpEdge(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->tick();
        self::assertTrue($world->enqueue($factory->move(
            'session',
            1,
            0.0,
            64.42,
            0.0,
            0.0,
            0.0,
            MovementMode::JUMPING,
            deltaY: 0.34,
            jumpRequested: true,
        )));
        $queuedBytes = $world->queuedBytes();
        for ($sequence = 2; $sequence <= 100; ++$sequence) {
            self::assertTrue($world->enqueue($factory->move(
                'session',
                $sequence,
                0.0,
                64.6,
                0.0,
                0.0,
                0.0,
                MovementMode::WALKING,
                deltaY: 0.2,
            )));
        }
        self::assertSame(1, $world->queuedCommands());
        self::assertSame($queuedBytes, $world->queuedBytes());
        self::assertSame(1, $world->tick()->processedCommands);
        $player = $world->snapshot()->players[0];
        self::assertSame(100, $player->movementSequence);
        self::assertSame(VerticalState::AIRBORNE, $player->verticalState);
        self::assertEqualsWithDelta(64.6, $player->position->y, 0.000001);
    }

    public function testQueuedJoinCannotBeStarvedByReservedMovementSlot(): void
    {
        $limits = new SimulationLimits(maximumCommandsPerTick: 1);
        $factory = new SimulationCommandFactory($limits);
        $world = new WorldSimulation($limits);
        $world->enqueue($factory->join('session', 'identity', 'Player'));
        $world->enqueue($factory->move('session', 1, 0.0, 64.0, 0.0, 0.0, 0.0, MovementMode::STOPPED));
        $tick = $world->tick();
        self::assertSame(1, $tick->processedCommands);
        self::assertInstanceOf(PlayerJoined::class, $tick->events[0]);
        self::assertSame(1, $world->queuedCommands());
    }

    public function testChatIsAttributedOrderedAndRateLimitedPerSender(): void
    {
        $limits = new SimulationLimits(chatBucketCapacity: 2, chatRefillTicks: 3);
        $factory = new SimulationCommandFactory($limits);
        $world = new WorldSimulation($limits);
        $world->enqueue($factory->join('one', 'trusted-identity', 'Trusted Name'));
        $world->enqueue($factory->join('two', 'identity-two', 'Two'));
        $world->tick();
        $world->enqueue($factory->chat('one', 1, 'first'));
        $world->enqueue($factory->chat('one', 2, 'second'));
        $world->enqueue($factory->chat('one', 3, 'limited'));

        $events = $world->tick()->events;
        self::assertInstanceOf(ChatBroadcast::class, $events[0]);
        self::assertSame('one', $events[0]->senderSessionId);
        self::assertSame('trusted-identity', $events[0]->senderIdentity);
        self::assertSame('Trusted Name', $events[0]->senderDisplayName);
        self::assertSame(['one', 'two'], $events[0]->recipients());
        $messages = [];
        foreach (array_slice($events, 0, 2) as $event) {
            self::assertInstanceOf(ChatBroadcast::class, $event);
            $messages[] = $event->message;
        }
        self::assertSame(['first', 'second'], $messages);
        self::assertInstanceOf(CommandRejected::class, $events[2]);
        self::assertSame('chat_rate', $events[2]->reason);

        for ($i = 0; $i < 2; ++$i) {
            $world->tick();
        }
        $world->enqueue($factory->chat('one', 3, 'replay'));
        $stale = $world->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $stale);
        self::assertSame('stale_chat_sequence', $stale->reason);
        $world->enqueue($factory->chat('one', 4, 'refilled'));
        self::assertInstanceOf(ChatBroadcast::class, $world->tick()->events[0]);
    }

    public function testDisconnectRemovesPlayerAndNotifiesOnlyRemainingPeers(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        $world->enqueue($factory->join('one', 'identity-one', 'One'));
        $world->enqueue($factory->join('two', 'identity-two', 'Two'));
        $world->tick();
        $world->enqueue($factory->disconnect('one'));

        $event = $world->tick()->events[0];
        self::assertInstanceOf(PlayerDisconnected::class, $event);
        self::assertSame('one', $event->sessionId);
        self::assertSame('identity-one', $event->identity);
        self::assertSame(['two'], $event->recipients());
        self::assertSame(['two'], array_map(static fn($player): string => $player->sessionId, $world->snapshot()->players));
    }

    public function testDisconnectHasReservedCapacityAndRejectsFurtherSessionWork(): void
    {
        $limits = new SimulationLimits(maximumQueuedCommands: 2);
        $factory = new SimulationCommandFactory($limits);
        $world = new WorldSimulation($limits);
        self::assertTrue($world->enqueue($factory->join('victim', 'identity', 'Victim')));
        self::assertTrue($world->enqueue($factory->join('attacker', 'other-identity', 'Attacker')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->chat('attacker', 1, 'first')));
        self::assertTrue($world->enqueue($factory->chat('attacker', 2, 'second')));

        self::assertTrue($world->enqueue($factory->disconnect('victim')));
        self::assertFalse($world->enqueue($factory->chat('victim', 1, 'late')));
        self::assertSame(3, $world->queuedCommands());
        $events = $world->tick()->events;
        self::assertInstanceOf(PlayerDisconnected::class, $events[0]);
        self::assertSame(['attacker'], array_map(static fn($player): string => $player->sessionId, $world->snapshot()->players));
        self::assertSame(0, $world->queuedCommands());
        self::assertSame(0, $world->queuedBytes());
    }

    public function testUnknownDisconnectsCannotConsumeReservedLifecycleCapacity(): void
    {
        $limits = new SimulationLimits(
            maximumPlayers: 1,
            maximumQueuedLifecycleCommands: 1,
            maximumQueuedLifecycleBytes: 144,
        );
        $factory = new SimulationCommandFactory($limits);
        $world = new WorldSimulation($limits);
        for ($i = 0; $i < 100; ++$i) {
            self::assertTrue($world->enqueue($factory->disconnect("unknown-$i")));
        }
        self::assertSame(0, $world->queuedCommands());
        self::assertSame(0, $world->queuedBytes());

        self::assertTrue($world->enqueue($factory->join('one', 'identity', 'One')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->disconnect('one')));
        self::assertTrue($world->enqueue($factory->disconnect('one')));
        self::assertSame(1, $world->queuedCommands());
        self::assertSame($factory->disconnect('one')->estimatedBytes(), $world->queuedBytes());
    }

    public function testDisconnectCancelsAQueuedJoinBeforeItCanCreateAGhost(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        self::assertTrue($world->enqueue($factory->disconnect('session')));

        self::assertSame([], $world->tick()->events);
        self::assertSame([], $world->snapshot()->players);
        self::assertSame(0, $world->queuedCommands());
        self::assertSame(0, $world->queuedBytes());
    }

    public function testNumericSessionIdsRemainStringsInRecipientContracts(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('123', 'identity', 'Player')));

        $event = $world->tick()->events[0];
        self::assertInstanceOf(PlayerJoined::class, $event);
        self::assertSame(['123'], $event->recipients());
    }

    public function testJoiningPlayerReceivesExistingDroppedItemActors(): void
    {
        $items = new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $existing = $items->spawn(
            new InventoryStack('minecraft:diamond', 2, 1),
            new Position(20.0, 70.0, 20.0),
            pickupDelayTicks: 20,
        );
        $world = new WorldSimulation(itemEntities: $items);
        self::assertTrue($world->enqueue((new SimulationCommandFactory())->join('one', 'identity-one', 'One')));

        $events = $world->tick()->events;
        $spawned = array_values(array_filter($events, static fn($event): bool => $event instanceof ItemEntitySpawned));
        self::assertCount(1, $spawned);
        self::assertSame($existing->runtimeEntityId, $spawned[0]->entity->runtimeEntityId);
        self::assertSame(['one'], $spawned[0]->recipients());
    }

    public function testItemEntitySettlesOnFlatWorldWithoutHoveringOrDisappearing(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $blocks = new World(new WorldMetadata('item-ground-test', 0), new FlatWorldGenerator($palette), new ChunkRepository(4));
        $items = new ItemEntityRegistry();
        $entity = $items->spawn(
            new InventoryStack('minecraft:cobblestone', 1, 1),
            new Position(0.5, 64.2, 0.5),
            pickupDelayTicks: 100,
        );
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette, itemEntities: $items);

        $first = $world->tick()->events;
        $firstMovement = array_values(array_filter($first, static fn($event): bool => $event instanceof ItemEntityMoved));
        self::assertCount(1, $firstMovement);
        self::assertFalse($firstMovement[0]->motionChanged);
        $second = $world->tick()->events;
        $secondMovement = array_values(array_filter($second, static fn($event): bool => $event instanceof ItemEntityMoved));
        self::assertCount(1, $secondMovement);
        self::assertTrue($secondMovement[0]->motionChanged);
        for ($tick = 0; $tick < 40; ++$tick) {
            $world->tick();
        }

        self::assertSame(1, $items->count());
        $settled = $items->get($entity->runtimeEntityId);
        self::assertNotNull($settled);
        self::assertSame(64.0, $settled->position->y);
        self::assertSame(0.0, $settled->motion->y);
    }

    public function testDropAtomicallyRemovesInventoryAndSpawnsTheAuthoritativeItemActor(): void
    {
        $data = BedrockDataSet::bundled();
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            $data->blockStateRegistry()->states(),
        ));
        $items = new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $world = new WorldSimulation(blockPalette: $palette, itemEntities: $items);
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->dropItem(
            'one',
            -3,
            new InventorySlotReference(InventoryContainer::Main, 0, 1, expectedCount: 64),
            2,
            InventoryResponseMode::ItemStackResponse,
            new InventoryStack('minecraft:grass_block', 64, 1, $palette->grassBlock),
        )));

        $events = $world->tick()->events;
        $processed = array_values(array_filter(
            $events,
            static fn($event): bool => $event instanceof InventoryStackRequestProcessed,
        ));
        $spawned = array_values(array_filter(
            $events,
            static fn($event): bool => $event instanceof ItemEntitySpawned,
        ));
        self::assertCount(1, $processed);
        self::assertTrue($processed[0]->success);
        self::assertSame(62, $processed[0]->mainInventory[0]?->count);
        self::assertCount(1, $spawned);
        self::assertSame(2, $spawned[0]->entity->stack->count);
        self::assertSame(40, $spawned[0]->entity->pickupDelayTicks);
        self::assertEqualsWithDelta(65.3, $spawned[0]->entity->position->y, 0.000_01);
        self::assertCount(1, $items->all());
    }

    public function testQueueCountByteAndPerTickDrainLimitsAreExplicit(): void
    {
        $limits = new SimulationLimits(maximumQueuedCommands: 2, maximumQueuedBytes: 100, maximumCommandsPerTick: 1);
        $factory = new SimulationCommandFactory($limits);
        $world = new WorldSimulation($limits);
        self::assertTrue($world->enqueue($factory->join('a', 'identity-a', 'A')));
        self::assertTrue($world->enqueue($factory->join('b', 'identity-b', 'B')));
        self::assertFalse($world->enqueue($factory->join('c', 'identity-c', 'C')));
        self::assertSame(2, $world->queuedCommands());
        self::assertGreaterThan(0, $world->queuedBytes());
        self::assertSame(1, $world->tick()->processedCommands);
        self::assertSame(1, $world->queuedCommands());
        $world->tick();
        self::assertSame(0, $world->queuedBytes());

        $tiny = new WorldSimulation(new SimulationLimits(maximumQueuedBytes: 10));
        self::assertFalse($tiny->enqueue($factory->join('a', 'identity-a', 'A')));
    }

    public function testQueueRevalidatesCommandsCreatedOutsideTheBoundary(): void
    {
        $world = new WorldSimulation();
        self::assertFalse($world->enqueue(new UnvalidatedJoinPlayer('', 'identity', 'Player')));
        self::assertSame(0, $world->queuedCommands());
    }

    public function testDeterministicReplayProducesIdenticalSnapshotsAndEvents(): void
    {
        $factory = new SimulationCommandFactory();
        $commands = [
            $factory->join('two', 'identity-two', 'Two'),
            $factory->join('one', 'identity-one', 'One'),
            $factory->move('one', 1, 2.0, 64.0, 0.0, 45.0, 0.0, MovementMode::WALKING),
            $factory->chat('two', 1, 'hello'),
            $factory->disconnect('one'),
        ];
        $first = new WorldSimulation();
        $second = new WorldSimulation();
        foreach ($commands as $command) {
            self::assertTrue($first->enqueue($command));
            self::assertTrue($second->enqueue($command));
        }

        self::assertEquals($first->tick(), $second->tick());
        self::assertEquals($first->snapshot(), $second->snapshot());
    }

    public function testShutdownLifecycleDrainDoesNotApplyQueuedGameplay(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('one', 'identity-one', 'One')));
        self::assertTrue($world->enqueue($factory->join('two', 'identity-two', 'Two')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->chat('two', 1, 'must not run during shutdown')));
        self::assertTrue($world->enqueue($factory->disconnect('one')));
        self::assertSame(2, $world->queuedCommands());

        $world->beginShutdown();
        $world->beginShutdown();
        self::assertSame(1, $world->queuedCommands(), 'Queued gameplay is discarded when shutdown begins.');
        self::assertFalse($world->enqueue($factory->chat('two', 2, 'must be rejected during shutdown')));

        $tick = $world->drainLifecycle();

        self::assertSame(1, $tick->processedCommands);
        self::assertCount(1, $tick->events);
        self::assertInstanceOf(PlayerDisconnected::class, $tick->events[0]);
        self::assertSame(0, $world->queuedLifecycleCommands());
        self::assertSame(0, $world->queuedCommands());
        self::assertSame(
            ['two'],
            array_map(static fn($player): string => $player->sessionId, $world->snapshot()->players),
        );
    }

    public function testHundredPlayerJoinMoveChatDisconnectStressLeavesNoGhosts(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        for ($i = 0; $i < 100; ++$i) {
            self::assertTrue($world->enqueue($factory->join("session-$i", "identity-$i", "Player$i")));
        }
        self::assertCount(100, $world->tick()->events);
        for ($i = 0; $i < 100; ++$i) {
            self::assertTrue($world->enqueue($factory->move("session-$i", 1, 1.0, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING)));
            self::assertTrue($world->enqueue($factory->chat("session-$i", 1, "message-$i")));
            self::assertTrue($world->enqueue($factory->disconnect("session-$i")));
        }
        $events = $world->tick()->events;
        self::assertCount(100, $events);
        foreach ($events as $event) {
            self::assertInstanceOf(PlayerDisconnected::class, $event);
        }
        self::assertSame([], $world->snapshot()->players);
        self::assertSame(0, $world->queuedCommands());
        self::assertSame(0, $world->queuedBytes());
    }

    private static function retainOriginCollisionTerrain(World $world): void
    {
        foreach ([-1, 0] as $chunkX) {
            foreach ([-1, 0] as $chunkZ) {
                $world->retainChunk(new ChunkPosition($chunkX, $chunkZ));
            }
        }
    }
}
