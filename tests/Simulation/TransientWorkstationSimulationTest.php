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

use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Inventory\InventoryActionType;
use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Server\Gameplay\Processing\TransientWorkstationType;
use Bedriox\Server\Inventory\SimpleContainerInventory;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Player\OpenedContainerInventory;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Simulation\Command\ApplyInventoryStackRequest;
use Bedriox\Server\Simulation\Command\WorkstationRequest;
use Bedriox\Server\Simulation\Command\WorkstationRequestType;
use Bedriox\Server\Simulation\Event\InventorySlotChanged;
use Bedriox\Server\Simulation\PlayerContainerSession;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TransientWorkstationSimulationTest extends TestCase
{
    public function testClosingTransientWorkstationReturnsInputsWithTargetedSlotUpdates(): void
    {
        $simulation = new WorldSimulation();
        $player = new Player(
            'session@192.168.1.14:19132',
            1,
            new PlayerIdentity('00000000-0000-0000-0000-000000000001', 'Player'),
            new Position(0.5, 64.0, 0.5),
            5,
            0,
            64.0,
        );
        $inventory = new SimpleContainerInventory(
            'workstation/session/anvil',
            3,
            [new ApiItemStack('minecraft:stone', 2), null, null],
        );
        $session = new PlayerContainerSession(
            2,
            ContainerType::ANVIL,
            $inventory,
            new OpenedContainerInventory($inventory->identifier(), 3, []),
            $inventory->revision(),
        );

        (new ReflectionMethod(WorldSimulation::class, 'returnTransientWorkstationInputs'))
            ->invoke($simulation, $player, $session);
        $events = $simulation->tick()->events;
        $slotEvents = array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof InventorySlotChanged,
        ));

        self::assertCount(1, $slotEvents);
        self::assertSame(0, $slotEvents[0]->slot);
        self::assertNotNull($slotEvents[0]->stack);
        self::assertSame('minecraft:stone', $slotEvents[0]->stack->identifier);
        self::assertSame(2, $slotEvents[0]->stack->count);
    }

    public function testModernStonecutterBlockAndPortableSessionInventoryIdentifiersAreSupported(): void
    {
        self::assertSame(
            TransientWorkstationType::STONECUTTER,
            TransientWorkstationType::fromBlockIdentifier('minecraft:stonecutter_block'),
        );

        $identifier = (new ReflectionMethod(WorldSimulation::class, 'transientWorkstationInventoryIdentifier'))
            ->invoke(
                null,
                '-4967907271641911058@192.168.1.14:61681',
                new BlockPosition(20, 65, -199),
                ContainerType::ANVIL,
            );

        self::assertIsString($identifier);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.:\/-]+$/D', $identifier);
        self::assertStringNotContainsString('@', $identifier);
        new SimpleContainerInventory($identifier, ContainerType::ANVIL->slotCount() ?? 0);
    }

    public function testCreatedOutputAndClientConsumptionAreNormalizedToTheServerOwnedResultSlot(): void
    {
        $simulation = new WorldSimulation();
        $inventory = new SimpleContainerInventory('workstation/session/stonecutter', 2);
        $session = new PlayerContainerSession(
            1,
            ContainerType::STONECUTTER,
            $inventory,
            new OpenedContainerInventory(
                $inventory->identifier(),
                2,
                [1 => new InventoryStack('minecraft:stone_slab', 1, 41)],
            ),
            $inventory->revision(),
        );
        $created = new InventorySlotReference(InventoryContainer::CreatedOutput, 50, 0);
        $destination = new InventorySlotReference(InventoryContainer::Main, 0, 0);
        $input = new InventorySlotReference(InventoryContainer::OpenedContainer, 0, 0);
        $command = new ApplyInventoryStackRequest(
            'session',
            -1,
            [
                new InventoryStackRequestAction(InventoryStackRequestActionType::Consume, $input, $input, 1),
                new InventoryStackRequestAction(InventoryStackRequestActionType::Take, $created, $destination, 1),
            ],
            workstation: new WorkstationRequest(WorkstationRequestType::OPTIONAL_RECIPE, 12),
        );

        $normalized = (new ReflectionMethod(WorldSimulation::class, 'normalizeTransientWorkstationRequest'))
            ->invoke($simulation, $session, $command);

        self::assertInstanceOf(ApplyInventoryStackRequest::class, $normalized);
        self::assertCount(1, $normalized->actions);
        self::assertSame(InventoryContainer::OpenedContainer, $normalized->actions[0]->source->container);
        self::assertSame(1, $normalized->actions[0]->source->slot);
        self::assertSame(InventoryContainer::Main, $normalized->actions[0]->destination->container);
        self::assertSame($command->workstation, $normalized->workstation);
    }

    public function testCreatedOutputUsesTheAuthoritativePreviewNetworkId(): void
    {
        $simulation = new WorldSimulation();
        $inventory = new SimpleContainerInventory('workstation/session/anvil', 3);
        $session = new PlayerContainerSession(
            1,
            ContainerType::ANVIL,
            $inventory,
            new OpenedContainerInventory(
                $inventory->identifier(),
                3,
                [2 => new InventoryStack('minecraft:iron_pickaxe', 1, 47)],
            ),
            $inventory->revision(),
        );
        $created = new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -379);
        $destination = new InventorySlotReference(InventoryContainer::Main, 8, 0);
        $command = new ApplyInventoryStackRequest(
            'session',
            -379,
            [new InventoryStackRequestAction(InventoryStackRequestActionType::Place, $created, $destination, 1)],
            workstation: new WorkstationRequest(WorkstationRequestType::OPTIONAL_RECIPE, 0),
        );

        $normalized = (new ReflectionMethod(WorldSimulation::class, 'normalizeTransientWorkstationRequest'))
            ->invoke($simulation, $session, $command);

        self::assertInstanceOf(ApplyInventoryStackRequest::class, $normalized);
        self::assertSame(InventoryContainer::OpenedContainer, $normalized->actions[0]->source->container);
        self::assertSame(2, $normalized->actions[0]->source->slot);
        self::assertSame(47, $normalized->actions[0]->source->expectedStackNetworkId);
    }

    public function testServerComputedWorkstationChangesProducePluginTransactionActions(): void
    {
        $simulation = new WorldSimulation();
        $player = new Player(
            'session@192.168.1.14:19132',
            1,
            new PlayerIdentity('00000000-0000-0000-0000-000000000001', 'Player'),
            new Position(0.5, 64.0, 0.5),
            5,
            0,
            64.0,
        );
        $container = new SimpleContainerInventory('workstation/session/enchanting', 2);
        $before = new OpenedContainerInventory($container->identifier(), 2, [
            0 => new InventoryStack('minecraft:book', 1, 41),
            1 => new InventoryStack('minecraft:lapis_lazuli', 3, 42),
        ]);
        $after = new OpenedContainerInventory($container->identifier(), 2, [
            0 => new InventoryStack('minecraft:enchanted_book', 1, 43),
            1 => new InventoryStack('minecraft:lapis_lazuli', 2, 44),
        ]);
        $command = new ApplyInventoryStackRequest(
            $player->sessionId,
            -439,
            [],
            workstation: new WorkstationRequest(WorkstationRequestType::ENCHANT, 6),
        );

        $transaction = (new ReflectionMethod(WorldSimulation::class, 'containerTransactionView'))->invoke(
            $simulation,
            $player,
            $command,
            clone $player->inventory,
            $before,
            clone $player->inventory,
            $after,
            $container,
        );

        self::assertInstanceOf(\Bedriox\Api\Inventory\InventoryTransaction::class, $transaction);
        self::assertCount(2, $transaction->actions);
        self::assertSame([0, 1], array_map(static fn($action): int => $action->slot, $transaction->actions));
        self::assertSame(
            [InventoryActionType::SLOT_CHANGE, InventoryActionType::SLOT_CHANGE],
            array_map(static fn($action): InventoryActionType => $action->type, $transaction->actions),
        );
    }
}
