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

use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Api\Player\GameMode;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Item\ItemBehaviorRegistry;
use Bedriox\Server\Gameplay\Item\ItemUseBehavior;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\CommandValidationException;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\InstantItemUsed;
use Bedriox\Server\Simulation\Event\ItemConsumed;
use Bedriox\Server\Simulation\Event\ItemUseCancelled;
use Bedriox\Server\Simulation\Event\ItemUseStarted;
use Bedriox\Server\Simulation\Event\NutritionChanged;
use Bedriox\Server\Simulation\Event\PlayerHealed;
use Bedriox\Server\Simulation\ItemUseCancellationReason;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use PHPUnit\Framework\TestCase;

final class ItemUseSimulationTest extends TestCase
{
    public function testConsumptionRequiresTheAuthoritativeDurationAndCommitsExactlyOnce(): void
    {
        [$world, $factory] = self::worldWithPlayer('minecraft:apple', 2, food: 10.0, saturation: 2.0);
        self::assertTrue($world->enqueue($factory->useItem('session', 0)));
        $started = $world->tick()->events[0];
        self::assertInstanceOf(ItemUseStarted::class, $started);
        self::assertSame(32, $started->durationTicks);

        for ($tick = 0; $tick < 31; ++$tick) {
            $world->tick();
        }
        self::assertTrue($world->enqueue($factory->useItem('session', 0)));
        $events = $world->tick()->events;
        self::assertInstanceOf(ItemConsumed::class, $events[0]);
        self::assertSame(1, $events[0]->selectedStack?->count);
        self::assertSame(14.0, $events[0]->player->food);
        self::assertSame(4.4, $events[0]->player->saturation);
        self::assertCount(1, $events[0]->affectedSlots);
        self::assertSame(InventoryContainer::Main, $events[0]->affectedSlots[0]->container);
        self::assertSame(0, $events[0]->affectedSlots[0]->slot);
        self::assertInstanceOf(NutritionChanged::class, $events[1]);

        self::assertTrue($world->enqueue($factory->useItem('session', 0)));
        self::assertInstanceOf(ItemUseStarted::class, $world->tick()->events[0]);
    }

    public function testEarlyCompletionAndReleaseCancelWithoutDeletingTheStack(): void
    {
        [$world, $factory] = self::worldWithPlayer('minecraft:apple', 2, food: 10.0);
        $world->enqueue($factory->useItem('session', 0));
        $world->tick();
        $world->enqueue($factory->useItem('session', 0));
        $early = $world->tick()->events[0];
        self::assertInstanceOf(ItemUseCancelled::class, $early);
        self::assertSame(ItemUseCancellationReason::TOO_EARLY, $early->reason);
        self::assertSame(2, $early->stack?->count);

        $world->enqueue($factory->useItem('session', 0));
        $world->tick();
        $world->enqueue($factory->releaseItem('session', 0));
        $released = $world->tick()->events[0];
        self::assertInstanceOf(ItemUseCancelled::class, $released);
        self::assertSame(ItemUseCancellationReason::RELEASED, $released->reason);
        self::assertSame(2, $released->stack?->count);
    }

    public function testSelectedSlotChangeAndDeathInterruptActiveUse(): void
    {
        [$slotWorld, $factory] = self::worldWithPlayer('minecraft:apple', 2, food: 10.0);
        $slotWorld->enqueue($factory->useItem('session', 0));
        $slotWorld->tick();
        $slotWorld->enqueue($factory->selectHotbarSlot('session', 1));
        $slotEvents = $slotWorld->tick()->events;
        $slotCancelled = array_values(array_filter(
            $slotEvents,
            static fn($event): bool => $event instanceof ItemUseCancelled,
        ));
        self::assertCount(1, $slotCancelled);
        self::assertSame(ItemUseCancellationReason::HELD_ITEM_CHANGED, $slotCancelled[0]->reason);

        [$deathWorld, $factory] = self::worldWithPlayer(
            'minecraft:apple',
            2,
            health: 1.0,
            food: 10.0,
        );
        $deathWorld->enqueue($factory->useItem('session', 0));
        $deathWorld->tick();
        $deathWorld->enqueue($factory->damage('session', 1.0, DamageCause::Plugin));
        $deathEvents = $deathWorld->tick()->events;
        $deathCancelled = array_values(array_filter(
            $deathEvents,
            static fn($event): bool => $event instanceof ItemUseCancelled,
        ));
        self::assertCount(1, $deathCancelled);
        self::assertSame(ItemUseCancellationReason::DEATH, $deathCancelled[0]->reason);
    }

    public function testUnsupportedHeldItemIsCorrectedWithoutMutation(): void
    {
        [$world, $factory] = self::worldWithPlayer('minecraft:diamond', 3, food: 10.0);
        $world->enqueue($factory->useItem('session', 0));
        $event = $world->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $event);
        self::assertSame('item_not_usable', $event->reason);
        self::assertSame(3, $world->pluginPlayers()[0]->getInventory()->getItem(0)?->count);
    }

    public function testFullPlayerCannotBeginOrdinaryFoodUse(): void
    {
        [$world, $factory] = self::worldWithPlayer('minecraft:apple', 1);
        $world->enqueue($factory->useItem('session', 0));
        $event = $world->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $event);
        self::assertSame('food_full', $event->reason);
    }

    public function testItemUseBoundaryRejectsAnOutOfRangeHotbarSlot(): void
    {
        $this->expectException(CommandValidationException::class);
        (new SimulationCommandFactory())->useItem('session', 9);
    }

    public function testSingleServingSoupReturnsItsBowlToTheConsumedSlot(): void
    {
        [$world, $factory] = self::worldWithPlayer('minecraft:mushroom_stew', 1, food: 10.0);
        $world->enqueue($factory->useItem('session', 0));
        $world->tick();
        for ($tick = 0; $tick < 31; ++$tick) {
            $world->tick();
        }
        $world->enqueue($factory->useItem('session', 0));
        $event = $world->tick()->events[0];
        self::assertInstanceOf(ItemConsumed::class, $event);
        self::assertSame('minecraft:bowl', $event->selectedStack?->identifier);
        self::assertNull($event->droppedResidue);
    }

    public function testCreativeConsumptionRetainsTheAuthoritativeStack(): void
    {
        [$world, $factory] = self::worldWithPlayer(
            'minecraft:apple',
            2,
            food: 10.0,
            gameMode: GameMode::CREATIVE,
        );
        $world->enqueue($factory->useItem('session', 0));
        $world->tick();
        for ($tick = 0; $tick < 31; ++$tick) {
            $world->tick();
        }
        $world->enqueue($factory->useItem('session', 0));
        $event = $world->tick()->events[0];
        self::assertInstanceOf(ItemConsumed::class, $event);
        self::assertSame(2, $event->selectedStack?->count);
        self::assertSame([], $event->affectedSlots);
    }

    public function testEnchantedGoldenAppleRestoresNutritionWithoutRequiringHunger(): void
    {
        [$world, $factory] = self::worldWithPlayer(
            'minecraft:enchanted_golden_apple',
            1,
            food: 20.0,
            saturation: 10.0,
        );
        $world->enqueue($factory->useItem('session', 0));
        $world->tick();
        for ($tick = 0; $tick < 31; ++$tick) {
            $world->tick();
        }
        $world->enqueue($factory->useItem('session', 0));

        $event = $world->tick()->events[0];
        self::assertInstanceOf(ItemConsumed::class, $event);
        self::assertNull($event->selectedStack);
        self::assertSame(20.0, $event->player->food);
        self::assertSame(19.6, $event->player->saturation);
        $effects = $world->pluginPlayers()[0]->getEffects();
        self::assertSame(1, $effects->get(EffectType::REGENERATION)?->amplifier);
        self::assertSame(3, $effects->get(EffectType::ABSORPTION)?->amplifier);
        self::assertTrue($effects->has(EffectType::RESISTANCE));
        self::assertTrue($effects->has(EffectType::FIRE_RESISTANCE));
    }

    public function testHighHungerRegeneratesHealthAndChargesExhaustionAtVanillaCadence(): void
    {
        [$world] = self::worldWithPlayer('minecraft:apple', 1, health: 18.0, food: 20.0, saturation: 20.0);
        $events = [];
        for ($tick = 0; $tick < 79; ++$tick) {
            $events = $world->tick()->events;
        }
        self::assertCount(2, $events);
        self::assertInstanceOf(PlayerHealed::class, $events[0]);
        self::assertInstanceOf(NutritionChanged::class, $events[1]);
        self::assertSame(19.0, $events[0]->player->health);
        self::assertSame(19.0, $events[0]->player->saturation);
        self::assertSame(2.0, $events[0]->player->exhaustion);

        for ($tick = 0; $tick < 80; ++$tick) {
            $events = $world->tick()->events;
        }
        self::assertInstanceOf(PlayerHealed::class, $events[0]);
        self::assertSame(20.0, $events[0]->player->health);
        self::assertSame(17.0, $events[0]->player->saturation);
        self::assertSame(0.0, $events[0]->player->exhaustion);
    }

    public function testNaturalRegenerationStopsAtLowHungerFullHealthAndDeath(): void
    {
        [$low] = self::worldWithPlayer('minecraft:apple', 1, health: 10.0, food: 17.0);
        [$full] = self::worldWithPlayer('minecraft:apple', 1, health: 20.0, food: 20.0);
        [$dead] = self::worldWithPlayer('minecraft:apple', 1, health: 0.0, food: 20.0);
        for ($tick = 0; $tick < 160; ++$tick) {
            self::assertSame([], $low->tick()->events);
            self::assertSame([], $full->tick()->events);
            self::assertSame([], $dead->tick()->events);
        }
        self::assertSame(10.0, $low->snapshot()->players[0]->health);
        self::assertSame(20.0, $full->snapshot()->players[0]->health);
        self::assertSame(0.0, $dead->snapshot()->players[0]->health);
    }

    public function testPluginDefinedInstantUseCompletesWithoutMutatingTheStack(): void
    {
        $data = BedrockDataSet::bundled();
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            $data->blockStateRegistry()->states(),
        ));
        $behaviors = new ItemBehaviorRegistry([new ItemUseBehavior(
            'example:wand',
            0,
            cooldownTicks: 10,
            owner: 'Example',
            kind: ItemUseKind::INSTANT,
        )]);
        $world = new WorldSimulation(blockPalette: $palette, itemBehaviors: $behaviors);
        $factory = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('12345678-1234-5678-9abc-123456789abc', 'Player'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('example:wand', 1)),
            ], 0),
            1,
            1,
        );
        $world->enqueue($factory->join('session', $bootstrap->identity->uuid, 'Player', bootstrap: $bootstrap));
        $world->tick();
        $world->enqueue($factory->useItem('session', 0));

        self::assertInstanceOf(InstantItemUsed::class, $world->tick()->events[0]);
        self::assertSame('example:wand', $world->pluginPlayers()[0]->getInventory()->getItem(0)?->identifier);
        $world->enqueue($factory->useItem('session', 0));
        self::assertInstanceOf(CommandRejected::class, $world->tick()->events[0]);
    }

    /** @return array{WorldSimulation, SimulationCommandFactory} */
    private static function worldWithPlayer(
        string $identifier,
        int $count,
        float $health = 20.0,
        float $food = 20.0,
        float $saturation = 20.0,
        float $exhaustion = 0.0,
        GameMode $gameMode = GameMode::SURVIVAL,
    ): array {
        $data = BedrockDataSet::bundled();
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            $data->blockStateRegistry()->states(),
        ));
        $world = new WorldSimulation(blockPalette: $palette);
        $factory = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('12345678-1234-5678-9abc-123456789abc', 'Player'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState($identifier, $count)),
            ], 0),
            1,
            1,
            $gameMode->value,
            $health,
            $food,
            $saturation,
            $exhaustion,
        );
        self::assertTrue($world->enqueue($factory->join(
            'session',
            $bootstrap->identity->uuid,
            $bootstrap->identity->displayName,
            bootstrap: $bootstrap,
        )));
        $world->tick();

        return [$world, $factory];
    }
}
