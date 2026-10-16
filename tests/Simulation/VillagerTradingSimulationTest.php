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

use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\ContainerSlotType;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Protocol\Packet\VillagerActorMetadata;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\VillagerEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Command\WorkstationRequest;
use Bedriox\Server\Simulation\Command\WorkstationRequestType;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class VillagerTradingSimulationTest extends TestCase
{
    private const string PLAYER_UUID = '00000000-0000-4000-8000-000000000021';

    public function testReachableVillagerTradeWindowSurvivesContainerValidationAndReleasesOwnershipOnClose(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('session', self::PLAYER_UUID, 'Trader', 71)));
        $simulation->tick();
        $villager = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::VILLAGER,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(VillagerEntity::class, $villager);
        $villager->setProfession(VillagerProfession::FARMER);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $villager->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $opened = self::event($simulation->tick()->events, ContainerOpened::class);
        self::assertInstanceOf(ContainerOpened::class, $opened);
        self::assertSame(71, $villager->getActiveTraderRuntimeId());

        self::assertNull(self::event($simulation->tick()->events, ContainerClosed::class));
        self::assertSame(71, $villager->getActiveTraderRuntimeId());

        self::assertTrue($simulation->enqueue($commands->closeContainer('session', $opened->windowId)));
        self::assertInstanceOf(ContainerClosed::class, self::event($simulation->tick()->events, ContainerClosed::class));
        self::assertNull($villager->getActiveTraderRuntimeId());
    }

    public function testPluginAdjustedTradeMustKeepItsKeyAndExactResultStack(): void
    {
        $nbt = ItemNbt::empty()->withString('bedriox:test', 'original');
        $offer = new VillagerTradeOffer(
            'bedriox:test_trade',
            VillagerLevel::NOVICE,
            new ItemStack('minecraft:emerald', 1),
            null,
            new ItemStack('minecraft:bread', 2, nbt: $nbt),
        );
        $check = new ReflectionMethod(WorldSimulation::class, 'tradeAdjustmentIsAtomic');

        self::assertTrue($check->invoke(null, $offer, $offer));
        self::assertFalse($check->invoke(null, $offer, new VillagerTradeOffer(
            'bedriox:other_trade',
            VillagerLevel::NOVICE,
            $offer->firstInput,
            null,
            $offer->result,
        )));
        self::assertFalse($check->invoke(null, $offer, new VillagerTradeOffer(
            $offer->key,
            VillagerLevel::NOVICE,
            $offer->firstInput,
            null,
            new ItemStack('minecraft:bread', 1, nbt: $nbt),
        )));
        self::assertFalse($check->invoke(null, $offer, new VillagerTradeOffer(
            $offer->key,
            VillagerLevel::NOVICE,
            $offer->firstInput,
            null,
            new ItemStack(
                'minecraft:bread',
                2,
                nbt: ItemNbt::empty()->withString('bedriox:test', 'changed'),
            ),
        )));
    }

    public function testOneAndTwoInputTradesCommitThroughThePublicStackRequestPath(): void
    {
        $simulation = new WorldSimulation(
            entityAiEnabled: false,
            itemCatalog: ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry()),
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('session', self::PLAYER_UUID, 'Trader', 71)));
        $simulation->tick();
        $villager = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::VILLAGER,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(VillagerEntity::class, $villager);
        $villager->setProfession(VillagerProfession::FARMER);
        $villager->replaceTrades(new VillagerTradeList([
            new VillagerTradeOffer(
                'bedriox:one_input',
                VillagerLevel::NOVICE,
                new ItemStack('minecraft:emerald', 1),
                null,
                new ItemStack('minecraft:bread', 2),
            ),
            new VillagerTradeOffer(
                'bedriox:two_input',
                VillagerLevel::NOVICE,
                new ItemStack('minecraft:emerald', 2),
                new ItemStack('minecraft:book', 1),
                new ItemStack('minecraft:bookshelf', 1),
            ),
        ], 4));

        self::assertTrue($simulation->enqueueGiveItem(self::PLAYER_UUID, 'minecraft:emerald', 3));
        $given = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $given);
        self::assertTrue($simulation->enqueueGiveItem(self::PLAYER_UUID, 'minecraft:book', 1));
        $given = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $given);
        $emerald = $given->mainInventory[0];
        $book = $given->mainInventory[1];
        self::assertNotNull($emerald);
        self::assertNotNull($book);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $villager->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $opened = self::event($simulation->tick()->events, ContainerOpened::class);
        self::assertInstanceOf(ContainerOpened::class, $opened);
        self::assertSame(71, $villager->getActiveTraderRuntimeId());

        $moved = self::executeRequest($simulation, $commands, -1, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Main, 0, $emerald->stackNetworkId),
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                0,
                0,
                ContainerSlotType::TradeTwoIngredientOne->value,
                responseSlot: 4,
            ),
            3,
        )]);
        self::assertTrue($moved->success, $moved->reason);
        self::assertNotContains(ContainerSlotType::TradeIngredientOne->value, array_map(
            static fn(InventorySlotReference $slot): ?int => $slot->responseContainerId,
            $moved->affectedSlots,
        ));
        self::assertContains(ContainerSlotType::TradeTwoIngredientOne->value, array_map(
            static fn(InventorySlotReference $slot): ?int => $slot->responseContainerId,
            $moved->affectedSlots,
        ));
        self::assertContains(FullContainerName::CREATED_OUTPUT, array_map(
            static fn(InventorySlotReference $slot): ?int => $slot->responseContainerId,
            $moved->affectedSlots,
        ));
        $breadPreview = $moved->openedContainerInventory[2];
        self::assertNotNull($breadPreview);

        $first = self::executeRequest($simulation, $commands, -2, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -2),
            new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
            2,
        )], 1);
        self::assertTrue($first->success, $first->reason);
        $bread = $first->cursorStack;
        self::assertNotNull($bread);
        self::assertSame('minecraft:bread', $bread->identifier);
        self::assertSame(2, $bread->count);
        self::assertSame(1, $villager->getTrades()->offers[0]->uses);
        self::assertSame(0, $villager->getTrades()->offers[1]->uses);

        $selected = self::executeRequest($simulation, $commands, -3, [], 2);
        self::assertTrue($selected->success, $selected->reason);
        $remainingEmeralds = $selected->openedContainerInventory[0];
        self::assertNotNull($remainingEmeralds);
        self::assertSame(2, $remainingEmeralds->count);
        $moved = self::executeRequest($simulation, $commands, -4, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Main, 1, $book->stackNetworkId),
            new InventorySlotReference(InventoryContainer::OpenedContainer, 1, 0),
            1,
        )]);
        self::assertTrue($moved->success, $moved->reason);
        $bookshelfPreview = $moved->openedContainerInventory[2];
        self::assertNotNull($bookshelfPreview);

        $second = self::executeRequest($simulation, $commands, -5, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(
                InventoryContainer::CreatedOutput,
                50,
                -5,
            ),
            new InventorySlotReference(InventoryContainer::Main, 3, 0),
            1,
        )], 2);
        self::assertTrue($second->success, $second->reason);
        $bookshelf = $second->mainInventory[3];
        self::assertNotNull($bookshelf);
        self::assertSame('minecraft:bookshelf', $bookshelf->identifier);
        self::assertSame(1, $villager->getTrades()->offers[0]->uses);
        self::assertSame(1, $villager->getTrades()->offers[1]->uses);
        self::assertNull($second->openedContainerInventory[0]);
        self::assertNull($second->openedContainerInventory[1]);

        self::assertTrue($simulation->enqueue($commands->closeContainer('session', $opened->windowId)));
        self::assertInstanceOf(ContainerClosed::class, self::event($simulation->tick()->events, ContainerClosed::class));
        self::assertNull($villager->getActiveTraderRuntimeId());
    }

    public function testDefaultNoviceShepherdShearsTradeCommitsThroughRetailCreatedOutputSlot(): void
    {
        $simulation = new WorldSimulation(
            entityAiEnabled: false,
            itemCatalog: ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry()),
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('session', self::PLAYER_UUID, 'Trader', 71)));
        $simulation->tick();
        $villager = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::VILLAGER,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(VillagerEntity::class, $villager);
        $villager->setProfession(VillagerProfession::SHEPHERD);
        $networkId = null;
        foreach ($villager->getTrades()->offers as $index => $offer) {
            if ($offer->key === 'bedriox:shepherd/shears') {
                self::assertSame(VillagerLevel::NOVICE, $offer->level);
                $networkId = $index + 1;
                break;
            }
        }
        self::assertNotNull($networkId);

        self::assertTrue($simulation->enqueueGiveItem(self::PLAYER_UUID, 'minecraft:emerald', 2));
        $given = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $given);
        $emeralds = $given->mainInventory[0];
        self::assertNotNull($emeralds);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $villager->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        self::assertInstanceOf(ContainerOpened::class, self::event($simulation->tick()->events, ContainerOpened::class));

        $placed = self::executeRequest($simulation, $commands, -101, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Main, 0, $emeralds->stackNetworkId),
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                0,
                0,
                ContainerSlotType::TradeTwoIngredientOne->value,
                responseSlot: 4,
            ),
            2,
        )]);
        self::assertTrue($placed->success, $placed->reason);
        self::assertSame('minecraft:shears', $placed->openedContainerInventory[2]?->identifier);

        $taken = self::executeRequest($simulation, $commands, -102, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -102),
            new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
            1,
        )], $networkId);
        self::assertTrue($taken->success, $taken->reason);
        self::assertSame('minecraft:shears', $taken->cursorStack?->identifier);
        self::assertSame(1, $villager->getTrades()->offer('bedriox:shepherd/shears')?->uses);
        self::assertSame(1, $villager->getVillagerExperience());
    }

    public function testExplicitArmorerSelectionCannotFallBackToIronBoots(): void
    {
        $simulation = new WorldSimulation(
            entityAiEnabled: false,
            itemCatalog: ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry()),
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('session', self::PLAYER_UUID, 'Trader', 71)));
        $simulation->tick();
        $villager = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::VILLAGER,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(VillagerEntity::class, $villager);
        $villager->setProfession(VillagerProfession::ARMORER);
        $diamondChestplateNetworkId = self::tradeNetworkId($villager, 'bedriox:armorer/diamond_chestplate');

        self::assertTrue($simulation->enqueueGiveItem(self::PLAYER_UUID, 'minecraft:emerald', 21));
        $given = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $given);
        $emeralds = $given->mainInventory[0];
        self::assertNotNull($emeralds);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $villager->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        self::assertInstanceOf(ContainerOpened::class, self::event($simulation->tick()->events, ContainerOpened::class));

        $placed = self::executeRequest($simulation, $commands, -201, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Main, 0, $emeralds->stackNetworkId),
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                0,
                0,
                ContainerSlotType::TradeTwoIngredientOne->value,
                responseSlot: 4,
            ),
            21,
        )]);
        self::assertTrue($placed->success, $placed->reason);
        self::assertSame('minecraft:iron_boots', $placed->openedContainerInventory[2]?->identifier);

        $rejected = self::executeRequest($simulation, $commands, -202, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -202),
            new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
            1,
        )], $diamondChestplateNetworkId);
        self::assertFalse($rejected->success);
        self::assertSame('trade_offer_unavailable', $rejected->reason);
        self::assertNull($rejected->cursorStack);
        self::assertSame(21, $rejected->openedContainerInventory[0]?->count);
        self::assertSame(0, $villager->getTrades()->offer('bedriox:armorer/iron_boots')?->uses);
    }

    public function testMasterArmorerTradeCommitsTheSelectedDiamondChestplate(): void
    {
        $simulation = new WorldSimulation(
            entityAiEnabled: false,
            itemCatalog: ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry()),
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('session', self::PLAYER_UUID, 'Trader', 71)));
        $simulation->tick();
        $villager = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::VILLAGER,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(VillagerEntity::class, $villager);
        $villager->setProfession(VillagerProfession::ARMORER);
        $villager->setTradeProgress(VillagerLevel::MASTER, 250);
        $diamondChestplateNetworkId = self::tradeNetworkId($villager, 'bedriox:armorer/diamond_chestplate');

        self::assertTrue($simulation->enqueueGiveItem(self::PLAYER_UUID, 'minecraft:emerald', 21));
        $given = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $given);
        $emeralds = $given->mainInventory[0];
        self::assertNotNull($emeralds);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $villager->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $opened = self::event($simulation->tick()->events, ContainerOpened::class);
        self::assertInstanceOf(ContainerOpened::class, $opened);

        $placed = self::executeRequest($simulation, $commands, -211, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Main, 0, $emeralds->stackNetworkId),
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                0,
                0,
                ContainerSlotType::TradeTwoIngredientOne->value,
                responseSlot: 4,
            ),
            21,
        )]);
        self::assertTrue($placed->success, $placed->reason);
        self::assertNull($placed->openedContainerInventory[2]);

        $taken = self::executeRequest($simulation, $commands, -212, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -212),
            new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
            1,
        )], $diamondChestplateNetworkId);
        self::assertTrue($taken->success, $taken->reason);
        self::assertSame('minecraft:diamond_chestplate', $taken->cursorStack?->identifier);
        self::assertNull($taken->openedContainerInventory[0]);
        self::assertSame(1, $villager->getTrades()->offer('bedriox:armorer/diamond_chestplate')?->uses);
        self::assertSame(0, $villager->getTrades()->offer('bedriox:armorer/iron_boots')?->uses);

        $stored = self::executeRequest($simulation, $commands, -213, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(
                InventoryContainer::Cursor,
                0,
                $taken->cursorStack->stackNetworkId,
            ),
            new InventorySlotReference(InventoryContainer::Main, 1, 0),
            1,
        )]);
        self::assertTrue($stored->success, $stored->reason);
        self::assertSame('minecraft:diamond_chestplate', $stored->mainInventory[1]?->identifier);

        self::assertTrue($simulation->enqueue($commands->closeContainer('session', $opened->windowId)));
        self::assertInstanceOf(ContainerClosed::class, self::event($simulation->tick()->events, ContainerClosed::class));
    }

    public function testBulkTradeConsumesAndRewardsTheRequestedCraftCountAtomically(): void
    {
        $simulation = new WorldSimulation(
            entityAiEnabled: false,
            itemCatalog: ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry()),
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('session', self::PLAYER_UUID, 'Trader', 71)));
        $simulation->tick();
        $villager = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::VILLAGER,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(VillagerEntity::class, $villager);
        $villager->setProfession(VillagerProfession::FARMER);
        $villager->replaceTrades(new VillagerTradeList([
            new VillagerTradeOffer(
                'bedriox:bulk_bread',
                VillagerLevel::NOVICE,
                new ItemStack('minecraft:emerald', 1),
                null,
                new ItemStack('minecraft:bread', 6),
                maximumUses: 12,
            ),
        ], 4));

        self::assertTrue($simulation->enqueueGiveItem(self::PLAYER_UUID, 'minecraft:emerald', 10));
        $given = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $given);
        $emeralds = $given->mainInventory[0];
        self::assertNotNull($emeralds);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $villager->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        self::assertInstanceOf(ContainerOpened::class, self::event($simulation->tick()->events, ContainerOpened::class));

        $placed = self::executeRequest($simulation, $commands, -301, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Main, 0, $emeralds->stackNetworkId),
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                0,
                0,
                ContainerSlotType::TradeTwoIngredientOne->value,
                responseSlot: 4,
            ),
            10,
        )]);
        self::assertTrue($placed->success, $placed->reason);
        self::assertSame(6, $placed->openedContainerInventory[2]?->count);

        $traded = self::executeRequest($simulation, $commands, -302, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -302),
            new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
            60,
        )], 1, 10);

        self::assertTrue($traded->success, $traded->reason);
        self::assertNotNull($traded->cursorStack);
        self::assertSame('minecraft:bread', $traded->cursorStack->identifier);
        self::assertSame(60, $traded->cursorStack->count);
        self::assertNull($traded->openedContainerInventory[0]);
        self::assertSame(10, $villager->getTrades()->offer('bedriox:bulk_bread')?->uses);
        self::assertSame(10, $villager->getVillagerExperience());
        self::assertSame(VillagerLevel::APPRENTICE, $villager->getVillagerLevel());
    }

    public function testVillagerPublishesOneAuthoritativeActiveTrader(): void
    {
        $villager = new VillagerEntity(
            '00000000-0000-4000-8000-000000000099',
            91,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $villager->requestNavigation(new Position(8.0, 64.0, 8.0), 0.1);
        $villager->applyMotion(new \Bedriox\Server\Entity\EntityMotion(0.1, 0.0, 0.1));
        self::assertTrue($villager->beginTrading(71));
        self::assertFalse($villager->beginTrading(72));
        self::assertTrue($villager->hasExclusiveAiActivity());
        self::assertNull($villager->navigationTarget());
        self::assertSame(0.0, $villager->getMotion()->lengthSquared());

        $metadata = (new BedrockLivingActorProjector())->metadata($villager);
        self::assertSame(71, self::integer($metadata, VillagerActorMetadata::TRADE_TARGET_UNIQUE_ID));
        self::assertNotSame(0, self::integer($metadata, 0) & ActorFlag::TradeInterest->mask());

        $villager->endTrading(72);
        self::assertSame(71, $villager->getActiveTraderRuntimeId());
        $villager->endTrading(71);
        self::assertFalse($villager->hasExclusiveAiActivity());
        $metadata = (new BedrockLivingActorProjector())->metadata($villager);
        self::assertSame(-1, self::integer($metadata, VillagerActorMetadata::TRADE_TARGET_UNIQUE_ID));
        self::assertSame(0, self::integer($metadata, 0) & ActorFlag::TradeInterest->mask());
    }

    public function testSleepingVillagerPublishesBedAndSleepingMetadata(): void
    {
        $villager = new VillagerEntity(
            '00000000-0000-4000-8000-000000000099',
            91,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $villager->assignBed(new BlockPosition(4, 64, 6));
        $villager->setSleeping(true);

        $metadata = (new BedrockLivingActorProjector())->metadata($villager);
        self::assertNotSame(0, self::integer($metadata, 92) & ActorFlag::Sleeping->mask());
        $bed = null;
        foreach ($metadata as $entry) {
            if ($entry->id === 28) {
                $bed = $entry->value;
            }
        }
        self::assertEquals(new \Bedriox\Protocol\Packet\BlockPosition(4, 64, 6), $bed);
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $type
     * @return T|null
     */
    private static function event(array $events, string $type): ?object
    {
        foreach ($events as $event) {
            if ($event instanceof $type) {
                return $event;
            }
        }

        return null;
    }

    /** @param list<ActorMetadata> $metadata */
    private static function integer(array $metadata, int $id): int
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id && is_int($entry->value)) {
                return $entry->value;
            }
        }

        self::fail("Missing integer actor metadata {$id}.");
    }

    private static function tradeNetworkId(VillagerEntity $villager, string $offerKey): int
    {
        foreach ($villager->getTrades()->offers as $index => $offer) {
            if ($offer->key === $offerKey) {
                return $index + 1;
            }
        }

        self::fail("Missing villager trade offer {$offerKey}.");
    }

    /** @param list<InventoryStackRequestAction> $actions */
    private static function executeRequest(
        WorldSimulation $simulation,
        SimulationCommandFactory $commands,
        int $requestId,
        array $actions,
        ?int $tradeNetworkId = null,
        int $requestedCrafts = 1,
    ): InventoryStackRequestProcessed {
        $command = $commands->inventoryStackRequest(
            'session',
            $requestId,
            $actions,
            workstation: $tradeNetworkId === null
                ? null
                : new WorkstationRequest(
                    WorkstationRequestType::TRADE,
                    $tradeNetworkId,
                    requestedCrafts: $requestedCrafts,
                    responseSlots: [
                        new InventorySlotReference(
                            InventoryContainer::OpenedContainer,
                            0,
                            0,
                            ContainerSlotType::TradeIngredientOne->value,
                            responseSlot: 6,
                        ),
                        new InventorySlotReference(
                            InventoryContainer::OpenedContainer,
                            1,
                            0,
                            ContainerSlotType::TradeIngredientTwo->value,
                            responseSlot: 7,
                        ),
                        new InventorySlotReference(
                            InventoryContainer::OpenedContainer,
                            0,
                            0,
                            ContainerSlotType::TradeTwoIngredientOne->value,
                            responseSlot: 4,
                        ),
                        new InventorySlotReference(
                            InventoryContainer::OpenedContainer,
                            1,
                            0,
                            ContainerSlotType::TradeTwoIngredientTwo->value,
                            responseSlot: 5,
                        ),
                        new InventorySlotReference(
                            InventoryContainer::OpenedContainer,
                            2,
                            0,
                            FullContainerName::CREATED_OUTPUT,
                            responseSlot: 50,
                        ),
                    ],
                ),
        );
        if ($tradeNetworkId !== null) {
            self::assertNotNull($command->workstation);
        }
        self::assertTrue($simulation->enqueue($command));
        $processed = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $processed);

        return $processed;
    }
}
