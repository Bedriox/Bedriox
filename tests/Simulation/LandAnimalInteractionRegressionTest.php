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
use Bedriox\Api\Entity\Value\ArmadilloState;
use Bedriox\Api\Entity\Value\LeashHolderType;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityBrushedEvent;
use Bedriox\Api\Event\Entity\EntityBrushEvent;
use Bedriox\Api\Event\Entity\EntityTrustedEvent;
use Bedriox\Api\Event\Entity\EntityTrustEvent;
use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\ArmadilloEntity;
use Bedriox\Server\Entity\Vanilla\OcelotEntity;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\TameAttemptPresented;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;
use Throwable;

final class LandAnimalInteractionRegressionTest extends TestCase
{
    public function testCancelledOcelotTrustDoesNotConsumeFishOrPublishAttemptFeedback(): void
    {
        $dispatcher = self::dispatcher();
        $postEvents = 0;
        $dispatcher->register(
            'LandAnimalInteractionTest',
            EntityTrustEvent::class,
            static function (EntityTrustEvent $event): void {
                self::assertSame(2, $event->player->getInventory()->getHeldItem()?->count);
                $event->cancel();
            },
        );
        $dispatcher->register(
            'LandAnimalInteractionTest',
            EntityTrustedEvent::class,
            static function () use (&$postEvents): void {
                ++$postEvents;
            },
        );
        [$simulation, $commands, $playerId] = self::simulation(
            new FixedInteractionRandom(1),
            new PluginGameplayEventBridge($dispatcher),
        );
        $ocelot = self::spawnOcelot($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:cod', 2, 1));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $ocelot->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $events = $simulation->tick()->events;

        self::assertFalse($ocelot->isTrusting());
        self::assertSame(2, $player->inventory->selectedStack()?->count);
        self::assertSame(0, $postEvents);
        self::assertCount(0, self::eventsOf($events, TameAttemptPresented::class));
        $rejections = self::eventsOf($events, CommandRejected::class);
        self::assertCount(1, $rejections);
        self::assertSame('plugin_cancelled', $rejections[0]->reason);
    }

    public function testFailedAndSuccessfulOcelotTrustAttemptsConsumeOneFishAndPublishExactFeedback(): void
    {
        $failedDispatcher = self::dispatcher();
        $failedOrder = [];
        $failedDispatcher->register(
            'LandAnimalInteractionTest',
            EntityTrustEvent::class,
            static function () use (&$failedOrder): void {
                $failedOrder[] = 'pre';
            },
        );
        $failedDispatcher->register(
            'LandAnimalInteractionTest',
            EntityTrustedEvent::class,
            static function () use (&$failedOrder): void {
                $failedOrder[] = 'post';
            },
        );
        [$failedSimulation, $commands, $failedPlayerId] = self::simulation(
            new FixedInteractionRandom(3),
            new PluginGameplayEventBridge($failedDispatcher),
        );
        $failedOcelot = self::spawnOcelot($failedSimulation);
        $failedPlayer = $failedSimulation->authoritativePlayer($failedPlayerId);
        self::assertNotNull($failedPlayer);
        $failedPlayer->inventory->replaceSlot(0, new InventoryStack('minecraft:salmon', 2, 1));

        self::assertTrue($failedSimulation->enqueue($commands->interactEntity(
            'player',
            $failedOcelot->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $failedEvents = $failedSimulation->tick()->events;
        $failedFeedback = self::eventsOf($failedEvents, TameAttemptPresented::class);
        self::assertCount(1, $failedFeedback);
        self::assertFalse($failedFeedback[0]->succeeded);
        self::assertLessThan(
            self::eventIndex($failedEvents, TameAttemptPresented::class),
            self::eventIndex($failedEvents, HeldItemChanged::class),
        );
        self::assertFalse($failedOcelot->isTrusting());
        self::assertSame(1, $failedPlayer->inventory->selectedStack()?->count);
        self::assertSame([], $failedOrder);

        $successfulDispatcher = self::dispatcher();
        $successfulOrder = [];
        $successfulDispatcher->register(
            'LandAnimalInteractionTest',
            EntityTrustEvent::class,
            static function () use (&$successfulOrder): void {
                $successfulOrder[] = 'pre';
            },
        );
        $successfulDispatcher->register(
            'LandAnimalInteractionTest',
            EntityTrustedEvent::class,
            static function (EntityTrustedEvent $event) use (&$successfulOrder): void {
                self::assertTrue($event->entity->isTrusting());
                self::assertNull($event->player->getInventory()->getHeldItem());
                $successfulOrder[] = 'post';
            },
        );
        [$successfulSimulation, $commands, $successfulPlayerId] = self::simulation(
            new FixedInteractionRandom(1),
            new PluginGameplayEventBridge($successfulDispatcher),
        );
        $successfulOcelot = self::spawnOcelot($successfulSimulation);
        $successfulPlayer = $successfulSimulation->authoritativePlayer($successfulPlayerId);
        self::assertNotNull($successfulPlayer);
        $successfulPlayer->inventory->replaceSlot(0, new InventoryStack('minecraft:cod', 1, 1));

        self::assertTrue($successfulSimulation->enqueue($commands->interactEntity(
            'player',
            $successfulOcelot->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $successfulEvents = $successfulSimulation->tick()->events;
        $successfulFeedback = self::eventsOf($successfulEvents, TameAttemptPresented::class);
        self::assertCount(1, $successfulFeedback);
        self::assertTrue($successfulFeedback[0]->succeeded);
        self::assertLessThan(
            self::eventIndex($successfulEvents, TameAttemptPresented::class),
            self::eventIndex($successfulEvents, HeldItemChanged::class),
        );
        self::assertTrue($successfulOcelot->trustsPlayer($successfulPlayerId));
        self::assertNull($successfulPlayer->inventory->selectedStack());
        self::assertSame(['pre', 'post'], $successfulOrder);
    }

    public function testOcelotRejectsLeadUntilTrustIsEstablished(): void
    {
        [$simulation, $commands, $playerId] = self::simulation(new FixedInteractionRandom(1));
        $ocelot = self::spawnOcelot($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:lead', 2, 1));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $ocelot->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $rejected = self::eventsOf($simulation->tick()->events, CommandRejected::class);
        self::assertCount(1, $rejected);
        self::assertSame('entity_not_trusting', $rejected[0]->reason);
        self::assertFalse($ocelot->isLeashed());
        self::assertSame(2, self::heldStack($player)->count);

        $ocelot->setTrustedPlayerUniqueId($playerId);
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $ocelot->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();
        self::assertTrue($ocelot->isLeashed());
        self::assertSame(LeashHolderType::PLAYER, $ocelot->getLeashHolderType());
        self::assertSame($playerId, $ocelot->getLeashHolderUniqueId());
        self::assertSame(1, self::heldStack($player)->count);
    }

    public function testNearbyUndeadThreatRollsArmadilloUpAndItEventuallyRecovers(): void
    {
        [$simulation] = self::simulation(new FixedInteractionRandom(1));
        $armadillo = self::spawnArmadillo($simulation);
        $zombie = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::COMMAND,
            'world',
            new Position(2.0, 64.0, 0.5),
        ))->entity;
        self::assertNotNull($zombie);

        for ($tick = 0; $tick < 20; ++$tick) {
            $simulation->tick();
        }
        self::assertSame(ArmadilloState::ROLLED_UP, $armadillo->getState());

        $simulation->entityRuntime()->remove($zombie->getRuntimeId());
        for ($tick = 0; $tick < 260; ++$tick) {
            $simulation->tick();
        }
        self::assertSame(ArmadilloState::UNROLLED, $armadillo->getState());
    }

    public function testNaturalScuteSheddingRetriesCapacityAndCommitsExactlyOnce(): void
    {
        $items = new ItemEntityRegistry(1, 5_000);
        $filler = $items->spawn(
            new InventoryStack('minecraft:stone', 1, 1),
            new Position(1_000.0, 64.0, 1_000.0),
        );
        [$simulation] = self::simulation(new FixedInteractionRandom(1), itemEntities: $items);
        $armadillo = self::spawnArmadillo($simulation);
        $armadillo->resetScuteShedTimer(1);

        for ($tick = 0; $tick < 40 && $armadillo->getScuteShedTicks() !== 0; ++$tick) {
            $simulation->tick();
        }
        self::assertSame(0, $armadillo->getScuteShedTicks());
        self::assertCount(0, array_filter(
            $items->all(),
            static fn($item): bool => $item->stack->identifier === 'minecraft:armadillo_scute',
        ));

        $items->remove($filler->runtimeEntityId);
        $drops = [];
        for ($tick = 0; $tick < 40 && $drops === []; ++$tick) {
            $drops = self::scuteDrops($simulation->tick()->events);
        }
        self::assertCount(1, $drops);
        self::assertGreaterThan(0, $armadillo->getScuteShedTicks());
        for ($tick = 0; $tick < 40; ++$tick) {
            self::assertCount(0, self::scuteDrops($simulation->tick()->events));
        }
    }

    public function testArmadilloBrushCancellationPreventsDropDurabilityAndCooldown(): void
    {
        $dispatcher = self::dispatcher();
        $preEvents = 0;
        $postEvents = 0;
        $dispatcher->register(
            'LandAnimalInteractionTest',
            EntityBrushEvent::class,
            static function (EntityBrushEvent $event) use (&$preEvents): void {
                ++$preEvents;
                self::assertSame('minecraft:brush', $event->tool->identifier);
                self::assertSame('minecraft:armadillo_scute', $event->drop->identifier);
                $event->cancel();
            },
        );
        $dispatcher->register(
            'LandAnimalInteractionTest',
            EntityBrushedEvent::class,
            static function () use (&$postEvents): void {
                ++$postEvents;
            },
        );
        [$simulation, $commands, $playerId] = self::simulation(
            new FixedInteractionRandom(1),
            new PluginGameplayEventBridge($dispatcher),
        );
        $armadillo = self::spawnArmadillo($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:brush', 1, 1, damage: 7));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $armadillo->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $events = $simulation->tick()->events;

        self::assertSame(1, $preEvents);
        self::assertSame(0, $postEvents);
        self::assertSame(7, $player->inventory->selectedStack()?->damage);
        self::assertCount(0, self::eventsOf($events, ItemEntitySpawned::class));
        self::assertTrue($armadillo->canBeBrushed(2));
    }

    public function testArmadilloBrushUsesSixteenDurabilityAndSuppressesRapidRepeats(): void
    {
        $dispatcher = self::dispatcher();
        $order = [];
        $dispatcher->register(
            'LandAnimalInteractionTest',
            EntityBrushEvent::class,
            static function (EntityBrushEvent $event) use (&$order): void {
                self::assertSame($event->tool->damage, $event->player->getInventory()->getHeldItem()?->damage);
                $order[] = 'pre';
            },
        );
        $dispatcher->register(
            'LandAnimalInteractionTest',
            EntityBrushedEvent::class,
            static function (EntityBrushedEvent $event) use (&$order): void {
                self::assertSame('minecraft:armadillo_scute', $event->drop->identifier);
                self::assertSame(
                    $event->tool->damage + 16,
                    $event->player->getInventory()->getHeldItem()?->damage,
                );
                $order[] = 'post';
            },
        );
        [$simulation, $commands, $playerId] = self::simulation(
            new FixedInteractionRandom(1),
            new PluginGameplayEventBridge($dispatcher),
        );
        $armadillo = self::spawnArmadillo($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:brush', 1, 1, damage: 7));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $armadillo->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $firstEvents = $simulation->tick()->events;
        self::assertSame(23, self::heldStack($player)->damage);
        self::assertCount(1, self::scuteDrops($firstEvents));
        self::assertSame(['pre', 'post'], $order);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $armadillo->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $repeatEvents = $simulation->tick()->events;
        self::assertSame(23, self::heldStack($player)->damage);
        self::assertCount(0, self::scuteDrops($repeatEvents));
        self::assertSame(['pre', 'post'], $order);

        for ($tick = 0; $tick < ArmadilloEntity::BRUSH_COOLDOWN_TICKS - 2; ++$tick) {
            $simulation->tick();
        }
        self::assertTrue($armadillo->canBeBrushed(12));
        self::assertSame('minecraft:brush', self::heldStack($player)->identifier);
        self::assertSame(23, self::heldStack($player)->damage);
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $armadillo->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $laterEvents = $simulation->tick()->events;
        self::assertCount(0, self::eventsOf($laterEvents, CommandRejected::class));
        self::assertSame(39, self::heldStack($player)->damage);
        self::assertCount(1, self::scuteDrops($laterEvents));
        self::assertSame(['pre', 'post', 'pre', 'post'], $order);
    }

    public function testBabyOrCapacityRejectedBrushDoesNotWearToolOrStartCooldown(): void
    {
        [$babySimulation, $commands, $babyPlayerId] = self::simulation(new FixedInteractionRandom(1));
        $baby = self::spawnArmadillo($babySimulation, true);
        $babyPlayer = $babySimulation->authoritativePlayer($babyPlayerId);
        self::assertNotNull($babyPlayer);
        $babyPlayer->inventory->replaceSlot(0, new InventoryStack('minecraft:brush', 1, 1, damage: 48));
        self::assertTrue($babySimulation->enqueue($commands->interactEntity(
            'player',
            $baby->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        self::assertCount(0, self::scuteDrops($babySimulation->tick()->events));
        self::assertSame(48, $babyPlayer->inventory->selectedStack()?->damage);

        $items = new ItemEntityRegistry(capacity: 1, firstEntityId: 1_000_000_000);
        $items->spawn(
            new InventoryStack('minecraft:stone', 1, 1),
            new Position(20.0, 64.0, 20.0),
        );
        $capacityDispatcher = self::dispatcher();
        $capacityPreEvents = 0;
        $capacityDispatcher->register(
            'LandAnimalInteractionTest',
            EntityBrushEvent::class,
            static function () use (&$capacityPreEvents): void {
                ++$capacityPreEvents;
            },
        );
        [$fullSimulation, $commands, $fullPlayerId] = self::simulation(
            new FixedInteractionRandom(1),
            new PluginGameplayEventBridge($capacityDispatcher),
            itemEntities: $items,
        );
        $adult = self::spawnArmadillo($fullSimulation);
        $fullPlayer = $fullSimulation->authoritativePlayer($fullPlayerId);
        self::assertNotNull($fullPlayer);
        $fullPlayer->inventory->replaceSlot(0, new InventoryStack('minecraft:brush', 1, 1, damage: 48));
        self::assertTrue($fullSimulation->enqueue($commands->interactEntity(
            'player',
            $adult->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $events = $fullSimulation->tick()->events;
        self::assertSame(48, $fullPlayer->inventory->selectedStack()?->damage);
        self::assertTrue($adult->canBeBrushed(2));
        self::assertSame(0, $capacityPreEvents);
        $rejections = self::eventsOf($events, CommandRejected::class);
        self::assertCount(1, $rejections);
        self::assertSame('item_entity_capacity', $rejections[0]->reason);
    }

    public function testArmadilloBrushCanBreakAtTheAuthoritativeDurabilityBoundary(): void
    {
        [$simulation, $commands, $playerId] = self::simulation(new FixedInteractionRandom(1));
        $armadillo = self::spawnArmadillo($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:brush', 1, 1, damage: 49));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $armadillo->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $events = $simulation->tick()->events;

        self::assertNull($player->inventory->selectedStack());
        self::assertCount(1, self::scuteDrops($events));
    }

    public function testCreativeArmadilloBrushProducesScuteWithoutWearingTheBrush(): void
    {
        [$simulation, $commands, $playerId] = self::simulation(new FixedInteractionRandom(1));
        $armadillo = self::spawnArmadillo($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->setGameMode(GameMode::CREATIVE);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:brush', 1, 1, damage: 49));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $armadillo->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $events = $simulation->tick()->events;

        self::assertSame(49, self::heldStack($player)->damage);
        self::assertCount(1, self::scuteDrops($events));
    }

    /** @return array{WorldSimulation, SimulationCommandFactory, string} */
    private static function simulation(
        DropRandom $random,
        ?PluginGameplayEventBridge $bridge = null,
        ?ItemEntityRegistry $itemEntities = null,
    ): array {
        $playerId = EntityUuid::random();
        $simulation = new WorldSimulation(
            pluginEvents: $bridge,
            dropRandom: $random,
            itemEntities: $itemEntities,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', $playerId, 'Player')));
        $simulation->tick();

        return [$simulation, $commands, $playerId];
    }

    private static function spawnOcelot(WorldSimulation $simulation): OcelotEntity
    {
        $outcome = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::OCELOT,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(OcelotEntity::class, $outcome->entity);

        return $outcome->entity;
    }

    private static function spawnArmadillo(WorldSimulation $simulation, bool $baby = false): ArmadilloEntity
    {
        $outcome = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ARMADILLO,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(ArmadilloEntity::class, $outcome->entity);
        $outcome->entity->setBaby($baby);

        return $outcome->entity;
    }

    private static function heldStack(Player $player): InventoryStack
    {
        $held = $player->inventory->selectedStack();
        self::assertNotNull($held);

        return $held;
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function eventsOf(array $events, string $class): array
    {
        return array_values(array_filter($events, static fn(object $event): bool => $event instanceof $class));
    }

    /** @param list<object> $events */
    private static function eventIndex(array $events, string $class): int
    {
        foreach ($events as $index => $event) {
            if ($event instanceof $class) {
                return $index;
            }
        }

        self::fail("Expected event {$class} was not emitted.");
    }

    /** @param list<object> $events
     *  @return list<ItemEntitySpawned>
     */
    private static function scuteDrops(array $events): array
    {
        return array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:armadillo_scute',
        ));
    }

    private static function dispatcher(): EventDispatcher
    {
        return new EventDispatcher(
            new LandAnimalInteractionRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
    }
}

final class FixedInteractionRandom implements DropRandom
{
    public function __construct(private readonly int $trustRoll) {}

    public function integer(int $minimum, int $maximum): int
    {
        if ($minimum === 1 && $maximum === 3) {
            return min($maximum, max($minimum, $this->trustRoll));
        }

        return $minimum;
    }
}

final class LandAnimalInteractionRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'LandAnimalInteractionTest';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
