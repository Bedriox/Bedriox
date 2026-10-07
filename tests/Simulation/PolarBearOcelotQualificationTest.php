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
use Bedriox\Api\Entity\EntityTargetReason;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\PandaActivity;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityDamageEvent;
use Bedriox\Api\Event\Entity\EntityPickupItemEvent;
use Bedriox\Api\Event\Entity\EntityTargetChangedEvent;
use Bedriox\Api\Event\Entity\EntityTargetEvent;
use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\ChickenEntity;
use Bedriox\Server\Entity\Vanilla\FoxEntity;
use Bedriox\Server\Entity\Vanilla\OcelotEntity;
use Bedriox\Server\Entity\Vanilla\PandaEntity;
use Bedriox\Server\Entity\Vanilla\PolarBearEntity;
use Bedriox\Server\Entity\Vanilla\TurtleEntity;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

final class PolarBearOcelotQualificationTest extends TestCase
{
    public function testPolarBearGroupProvocationTargetsExactAttackerAndExcludesCub(): void
    {
        [$simulation, $player] = self::simulation();
        $provoked = self::polarBear($simulation, 1.5);
        $nearbyAdult = self::polarBear($simulation, 3.5);
        $cub = self::polarBear($simulation, 4.5, true);

        self::provokePolarBearGroup($simulation, $provoked, $player);

        foreach ([$provoked, $nearbyAdult] as $adult) {
            self::assertSame($player->identity->uuid, $adult->getAngerTargetUniqueId());
            self::assertGreaterThanOrEqual(400, $adult->getRemainingAngerTicks());
            self::assertLessThanOrEqual(800, $adult->getRemainingAngerTicks());
            self::assertTrue($adult->isStanding());
        }
        self::assertNull($cub->getAngerTargetUniqueId());
        self::assertSame(0, $cub->getRemainingAngerTicks());
        self::assertFalse($cub->isStanding());
    }

    public function testPolarBearGroupTargetCancellationPreventsEveryAuthoritativeTransition(): void
    {
        $dispatcher = self::dispatcher();
        $preEvents = 0;
        $postEvents = 0;
        $dispatcher->register(
            'PolarQualification',
            EntityTargetEvent::class,
            static function (EntityTargetEvent $event) use (&$preEvents): void {
                self::assertSame(EntityTargetReason::GROUP_PROVOCATION, $event->reason);
                ++$preEvents;
                $event->cancel();
            },
        );
        $dispatcher->register(
            'PolarQualification',
            EntityTargetChangedEvent::class,
            static function () use (&$postEvents): void {
                ++$postEvents;
            },
        );
        [$simulation, $player] = self::simulation(new PluginGameplayEventBridge($dispatcher));
        $provoked = self::polarBear($simulation, 1.5);
        $nearbyAdult = self::polarBear($simulation, 3.5);

        self::provokePolarBearGroup($simulation, $provoked, $player);

        self::assertSame(2, $preEvents);
        self::assertSame(0, $postEvents);
        foreach ([$provoked, $nearbyAdult] as $adult) {
            self::assertNull($adult->getAngerTargetUniqueId());
            self::assertSame(0, $adult->getRemainingAngerTicks());
            self::assertFalse($adult->isStanding());
        }
    }

    public function testPolarBearPersistenceRetainsCubStandingAndExactAngerState(): void
    {
        $target = EntityUuid::random();
        $bear = new PolarBearEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
            standing: true,
            angerTargetUniqueId: $target,
            angerTicks: 517,
        );
        $restored = new PolarBearEntity(
            EntityUuid::random(),
            2,
            'world',
            new Position(0.0, 64.0, 0.0),
        );

        $restored->restorePersistenceState(
            $bear->persistenceVariant(),
            $bear->persistenceSchemaVersion(),
            $bear->persistenceData(),
        );

        self::assertTrue($restored->isBaby());
        self::assertTrue($restored->isStanding());
        self::assertSame($target, $restored->getAngerTargetUniqueId());
        self::assertSame(517, $restored->getRemainingAngerTicks());
    }

    public function testPolarBearPersistenceRejectsUnknownFieldsAndOversizedPayloads(): void
    {
        $bear = new PolarBearEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $data = json_decode($bear->persistenceData(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['unexpected'] = true;

        try {
            $bear->restorePersistenceState(null, 1, json_encode($data, JSON_THROW_ON_ERROR));
            self::fail('Unknown polar-bear persistence fields must fail closed.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $bear->restorePersistenceState(null, 1, str_repeat('x', 513));
    }

    public function testPolarCubGrowthUsesFullTwentyMinuteTickDurationAndPersistsProgress(): void
    {
        $cub = new PolarBearEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
        );
        for ($seconds = 0; $seconds < 1_199; ++$seconds) {
            $cub->advanceGrowth(20);
        }
        self::assertTrue($cub->isBaby());

        $restored = new PolarBearEntity(
            EntityUuid::random(),
            2,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $restored->restorePersistenceState(
            $cub->persistenceVariant(),
            $cub->persistenceSchemaVersion(),
            $cub->persistenceData(),
        );
        self::assertTrue($restored->isBaby());
        $restored->advanceGrowth(20);
        self::assertFalse($restored->isBaby());
    }

    public function testSimulationDoesNotAcceleratePolarCubGrowthByTwentyTimes(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $cub = self::polarBear($simulation, 0.5, true);

        for ($tick = 0; $tick < 23_999; ++$tick) {
            $simulation->tick();
        }

        self::assertTrue($cub->isBaby(), 'A polar cub matured before its full 24,000-tick duration.');
        $simulation->tick();
        self::assertFalse($cub->isBaby());
    }

    public function testCubProtectionNeverTargetsNondamageablePlayer(): void
    {
        [$simulation, $player] = self::simulation();
        $player->setGameMode(GameMode::CREATIVE);
        $adult = self::polarBear($simulation, 1.5);
        self::polarBear($simulation, 2.5, true);

        $simulation->tick();

        self::assertNull($adult->getAngerTargetUniqueId());
        self::assertSame(0, $adult->getRemainingAngerTicks());
        self::assertFalse($adult->isStanding());
    }

    public function testPandaConsumesExactlyOneDroppedBambooAndPreservesRemainder(): void
    {
        $items = new ItemEntityRegistry(capacity: 4, firstEntityId: 900);
        $simulation = new WorldSimulation(
            itemEntities: $items,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $panda = self::panda($simulation, 0.5);
        $original = $items->spawn(
            new InventoryStack('minecraft:bamboo', 3, 1),
            new Position(0.75, 64.0, 0.5),
        );

        $events = self::collectPandaBamboo($simulation, $panda);

        self::assertSame(PandaActivity::EATING, $panda->getActivity());
        self::assertCount(2, $events);
        self::assertNull($items->get($original->runtimeEntityId));
        self::assertCount(1, $items->all());
        self::assertSame('minecraft:bamboo', $items->all()[0]->stack->identifier);
        self::assertSame(2, $items->all()[0]->stack->count);
        self::assertSame([], self::collectPandaBamboo($simulation, $panda));
        self::assertSame(2, $items->all()[0]->stack->count);
    }

    public function testCancelledPandaBambooPickupPreservesItemAndEatingState(): void
    {
        $dispatcher = self::dispatcher();
        $pickupEvents = 0;
        $dispatcher->register(
            'PolarQualification',
            EntityPickupItemEvent::class,
            static function (EntityPickupItemEvent $event) use (&$pickupEvents): void {
                ++$pickupEvents;
                $event->cancel();
            },
        );
        $items = new ItemEntityRegistry(capacity: 2, firstEntityId: 900);
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            itemEntities: $items,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $panda = self::panda($simulation, 0.5);
        $bamboo = $items->spawn(
            new InventoryStack('minecraft:bamboo', 2, 1),
            new Position(0.75, 64.0, 0.5),
        );

        self::assertSame([], self::collectPandaBamboo($simulation, $panda));
        self::assertSame(1, $pickupEvents);
        self::assertSame(PandaActivity::IDLE, $panda->getActivity());
        self::assertSame(2, $items->get($bamboo->runtimeEntityId)?->stack->count);
    }

    public function testExhaustedItemIdentityPreservesPartialBambooWithoutStartingEating(): void
    {
        $items = new ItemEntityRegistry(capacity: 2, firstEntityId: PHP_INT_MAX - 1);
        $simulation = new WorldSimulation(
            itemEntities: $items,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $panda = self::panda($simulation, 0.5);
        $bamboo = $items->spawn(
            new InventoryStack('minecraft:bamboo', 3, 1),
            new Position(0.75, 64.0, 0.5),
        );

        self::assertSame([], self::collectPandaBamboo($simulation, $panda));

        self::assertSame(PandaActivity::IDLE, $panda->getActivity());
        self::assertSame(1, $items->count());
        $retained = $items->get($bamboo->runtimeEntityId);
        self::assertSame($bamboo, $retained);
        self::assertSame(3, $retained->stack->count);
    }

    public function testTrustedOcelotParentProducesTrustedButUntamedOffspring(): void
    {
        [$simulation, $player] = self::simulation();
        $first = self::ocelot($simulation, 1.0);
        $second = self::ocelot($simulation, 1.5);
        $first->setTrustedPlayerUniqueId($player->identity->uuid);
        $first->setLoveTicks(600);
        $second->setLoveTicks(600);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:cod', 1, 1));

        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $first->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        $children = array_values(array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn($entity): bool => $entity instanceof OcelotEntity
                && $entity !== $first
                && $entity !== $second,
        ));
        self::assertCount(1, $children);
        self::assertTrue($children[0]->isBaby());
        self::assertTrue($children[0]->trustsPlayer($player->identity->uuid));
    }

    public function testOcelotPredationDamagesChickenAndBabyTurtleButNotAdultTurtle(): void
    {
        $chickenSimulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        self::ocelot($chickenSimulation, 0.5);
        $chicken = $chickenSimulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::CHICKEN,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(ChickenEntity::class, $chicken);
        self::advancePredation($chickenSimulation);
        self::assertSame(1.0, $chicken->getHealth());

        $adultSimulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        self::ocelot($adultSimulation, 0.5);
        $adultTurtle = $adultSimulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::TURTLE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(TurtleEntity::class, $adultTurtle);
        self::advancePredation($adultSimulation);
        self::assertSame($adultTurtle->getMaximumHealth(), $adultTurtle->getHealth());

        $babySimulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        self::ocelot($babySimulation, 0.5);
        $babyTurtle = $babySimulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::TURTLE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(TurtleEntity::class, $babyTurtle);
        $babyTurtle->setBaby(true);
        self::advancePredation($babySimulation);
        self::assertSame($babyTurtle->getMaximumHealth() - 3.0, $babyTurtle->getHealth());
    }

    public function testOnlyAdultPolarBearHuntsNearbyFox(): void
    {
        $adultSimulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        self::polarBear($adultSimulation, 0.5);
        $adultFox = $adultSimulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::FOX,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(FoxEntity::class, $adultFox);
        self::advancePredation($adultSimulation);
        self::assertSame(4.0, $adultFox->getHealth());

        $cubSimulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        self::polarBear($cubSimulation, 0.5, true);
        $cubFox = $cubSimulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::FOX,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(FoxEntity::class, $cubFox);
        self::advancePredation($cubSimulation);
        self::assertSame($cubFox->getMaximumHealth(), $cubFox->getHealth());
    }

    public function testCancelledOcelotPredationDoesNotDamagePreyOrStartInvulnerability(): void
    {
        $dispatcher = self::dispatcher();
        $damageEvents = 0;
        $dispatcher->register(
            'PolarQualification',
            EntityDamageEvent::class,
            static function (EntityDamageEvent $event) use (&$damageEvents): void {
                ++$damageEvents;
                $event->cancel();
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        self::ocelot($simulation, 0.5);
        $chicken = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::CHICKEN,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(ChickenEntity::class, $chicken);

        self::advancePredation($simulation);
        self::advancePredation($simulation);

        self::assertSame(2, $damageEvents);
        self::assertSame($chicken->getMaximumHealth(), $chicken->getHealth());
    }

    /** @return array{WorldSimulation, Player} */
    private static function simulation(?PluginGameplayEventBridge $events = null): array
    {
        $simulation = new WorldSimulation(
            pluginEvents: $events,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $playerId = EntityUuid::random();
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', $playerId, 'Player')));
        $simulation->tick();
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);

        return [$simulation, $player];
    }

    private static function polarBear(
        WorldSimulation $simulation,
        float $x,
        bool $baby = false,
    ): PolarBearEntity {
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::POLAR_BEAR,
            SpawnCause::COMMAND,
            'world',
            new Position($x, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(PolarBearEntity::class, $entity);
        $entity->setBaby($baby);

        return $entity;
    }

    private static function ocelot(WorldSimulation $simulation, float $x): OcelotEntity
    {
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::OCELOT,
            SpawnCause::COMMAND,
            'world',
            new Position($x, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(OcelotEntity::class, $entity);

        return $entity;
    }

    private static function panda(WorldSimulation $simulation, float $x): PandaEntity
    {
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::PANDA,
            SpawnCause::COMMAND,
            'world',
            new Position($x, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(PandaEntity::class, $entity);

        return $entity;
    }

    private static function provokePolarBearGroup(
        WorldSimulation $simulation,
        PolarBearEntity $provoked,
        Player $attacker,
    ): void {
        $method = new ReflectionMethod(WorldSimulation::class, 'provokePolarBearGroup');
        $method->invoke($simulation, $provoked, $attacker);
    }

    private static function advancePredation(WorldSimulation $simulation): void
    {
        $method = new ReflectionMethod(WorldSimulation::class, 'advanceLandAnimalPredation');
        $result = $method->invoke($simulation);
        self::assertIsArray($result);
    }

    /** @return list<object> */
    private static function collectPandaBamboo(WorldSimulation $simulation, PandaEntity $panda): array
    {
        $method = new ReflectionMethod(WorldSimulation::class, 'collectPandaBamboo');
        $result = $method->invoke($simulation, $panda);
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));
        $events = [];
        foreach ($result as $event) {
            self::assertIsObject($event);
            $events[] = $event;
        }

        return $events;
    }

    private static function dispatcher(): EventDispatcher
    {
        return new EventDispatcher(
            new PolarBearQualificationRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
    }
}

final class PolarBearQualificationRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'PolarQualification';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
