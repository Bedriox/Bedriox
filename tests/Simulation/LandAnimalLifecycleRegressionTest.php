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

use Bedriox\Api\Entity\Entity as ApiEntity;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\LeashDetachReason;
use Bedriox\Api\Entity\Value\LeashHolderType;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityUnleashedEvent;
use Bedriox\Api\Event\Entity\EntityUnleashEvent;
use Bedriox\Api\Event\Entity\ProjectileLaunchedEvent;
use Bedriox\Api\Event\Entity\ProjectileLaunchEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\WolfEntity;
use Bedriox\Server\Gameplay\Projectile\ProjectileRegistry;
use Bedriox\Server\Gameplay\Projectile\ProjectileType;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\ProjectileSpawned;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

final class LandAnimalLifecycleRegressionTest extends TestCase
{
    public function testUnavailableLeashHolderDetachesOnceDropsOneLeadAndDispatchesOnePostEvent(): void
    {
        $dispatcher = self::dispatcher();
        $observed = [];
        $dispatcher->register(
            'LandAnimalTest',
            EntityUnleashedEvent::class,
            static function (EntityUnleashedEvent $event) use (&$observed): void {
                $observed[] = [$event->holder, $event->reason];
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(LlamaEntity::class, $spawn->entity);
        $llama = $spawn->entity;
        $llama->setLeashHolder(EntityUuid::random(), 999);

        $leadDrops = [];
        for ($tick = 0; $tick < 25; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof ItemEntitySpawned && $event->entity->stack->identifier === 'minecraft:lead') {
                    $leadDrops[] = $event;
                }
            }
        }

        self::assertFalse($llama->isLeashed());
        self::assertSame([[null, LeashDetachReason::HOLDER_UNAVAILABLE]], $observed);
        self::assertCount(1, $leadDrops);
    }

    public function testAutomaticCleanupCannotBeCancelledIntoADanglingLeash(): void
    {
        $dispatcher = self::dispatcher();
        $preEvents = 0;
        $postEvents = 0;
        $dispatcher->register(
            'LandAnimalTest',
            EntityUnleashEvent::class,
            static function (EntityUnleashEvent $event) use (&$preEvents): void {
                ++$preEvents;
                $event->cancel();
            },
        );
        $dispatcher->register(
            'LandAnimalTest',
            EntityUnleashedEvent::class,
            static function () use (&$postEvents): void {
                ++$postEvents;
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(LlamaEntity::class, $spawn->entity);
        $llama = $spawn->entity;
        $llama->setLeashHolder(EntityUuid::random(), 999);

        $leadDropCount = 0;
        for ($tick = 0; $tick < 25; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof ItemEntitySpawned && $event->entity->stack->identifier === 'minecraft:lead') {
                    ++$leadDropCount;
                }
            }
        }

        self::assertFalse($llama->isLeashed());
        self::assertSame(0, $preEvents);
        self::assertSame(1, $postEvents);
        self::assertSame(1, $leadDropCount);
    }

    public function testDeathPreparationTransfersLeashStorageChestAndCarpetExactlyOnce(): void
    {
        $dispatcher = self::dispatcher();
        $unleashReasons = [];
        $dispatcher->register(
            'LandAnimalTest',
            EntityUnleashedEvent::class,
            static function (EntityUnleashedEvent $event) use (&$unleashReasons): void {
                self::assertNull($event->holder);
                $unleashReasons[] = $event->reason;
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
        );
        $llama = new LlamaEntity(
            EntityUuid::random(),
            51,
            'world',
            new Position(0.5, 64.0, 0.5),
            strength: 2,
            chested: true,
            carpetColor: WoolColor::LIME,
        );
        $llama->storageInventory()->setStack(0, new ItemStack('minecraft:diamond', 3));
        $llama->storageInventory()->setStack(5, new ItemStack('minecraft:apple', 2));
        $llama->setLeashHolder(EntityUuid::random(), 99);
        $prepare = new ReflectionMethod(WorldSimulation::class, 'prepareEntityDeathDrops');

        $first = $prepare->invoke($simulation, $llama, null);
        self::assertIsArray($first);
        $identifiers = [];
        foreach ($first as $drop) {
            self::assertInstanceOf(ItemStack::class, $drop);
            $identifiers[] = $drop->identifier;
        }
        $counts = array_count_values($identifiers);
        self::assertSame(1, $counts['minecraft:lead'] ?? 0);
        self::assertSame(1, $counts['minecraft:diamond'] ?? 0);
        self::assertSame(1, $counts['minecraft:apple'] ?? 0);
        self::assertSame(1, $counts['minecraft:chest'] ?? 0);
        self::assertSame(1, $counts['minecraft:lime_carpet'] ?? 0);

        self::assertSame([], $prepare->invoke($simulation, $llama, null));
        self::assertFalse($llama->isLeashed());
        self::assertFalse($llama->hasChest());
        self::assertNull($llama->getCarpetColor());
        self::assertSame(array_fill(0, 6, null), $llama->storageInventory()->contents());
        self::assertSame([LeashDetachReason::ENTITY_DEATH], $unleashReasons);
    }

    public function testEntityHolderCrossWorldCleanupDropsOnceAndPreservesEventAttribution(): void
    {
        $dispatcher = self::dispatcher();
        $observed = [];
        $dispatcher->register(
            'LandAnimalTest',
            EntityUnleashedEvent::class,
            static function (EntityUnleashedEvent $event) use (&$observed): void {
                self::assertInstanceOf(ApiEntity::class, $event->holder);
                $observed[] = [$event->holder->getUniqueId(), $event->reason];
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $leaderSpawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        $followerSpawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(LlamaEntity::class, $leaderSpawn->entity);
        self::assertInstanceOf(LlamaEntity::class, $followerSpawn->entity);
        $leader = $leaderSpawn->entity;
        $follower = $followerSpawn->entity;
        $follower->setLeashHolder(
            $leader->getUniqueId(),
            $leader->getRuntimeId(),
            LeashHolderType::ENTITY,
        );
        $simulation->entityRuntime()->registry()->move(
            $leader->getRuntimeId(),
            'other',
            $leader->internalPosition(),
            $leader->getYaw(),
            $leader->getPitch(),
        );

        $leadDropCount = 0;
        for ($tick = 0; $tick < 50; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof ItemEntitySpawned && $event->entity->stack->identifier === 'minecraft:lead') {
                    ++$leadDropCount;
                }
            }
        }

        self::assertFalse($follower->isLeashed());
        self::assertSame(1, $leadDropCount);
        self::assertSame([[$leader->getUniqueId(), LeashDetachReason::CROSS_WORLD]], $observed);
    }

    public function testRemovedEntityHolderCleanupDropsExactlyOneLead(): void
    {
        $dispatcher = self::dispatcher();
        $observed = [];
        $dispatcher->register(
            'LandAnimalTest',
            EntityUnleashedEvent::class,
            static function (EntityUnleashedEvent $event) use (&$observed): void {
                $observed[] = [$event->holder, $event->reason];
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $leaderSpawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        $followerSpawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(LlamaEntity::class, $leaderSpawn->entity);
        self::assertInstanceOf(LlamaEntity::class, $followerSpawn->entity);
        $leader = $leaderSpawn->entity;
        $follower = $followerSpawn->entity;
        $follower->setLeashHolder(
            $leader->getUniqueId(),
            $leader->getRuntimeId(),
            LeashHolderType::ENTITY,
        );
        $simulation->entityRuntime()->remove($leader->getRuntimeId());

        $leadDropCount = 0;
        for ($tick = 0; $tick < 50; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof ItemEntitySpawned && $event->entity->stack->identifier === 'minecraft:lead') {
                    ++$leadDropCount;
                }
            }
        }

        self::assertFalse($follower->isLeashed());
        self::assertSame(1, $leadDropCount);
        self::assertSame([[null, LeashDetachReason::HOLDER_UNAVAILABLE]], $observed);
    }

    public function testLlamaCaravanIsLinearBoundedAndRootedInALiveLeash(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $llamas = [];
        for ($index = 0; $index < 11; ++$index) {
            $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
                VanillaEntityType::LLAMA,
                SpawnCause::COMMAND,
                'world',
                new Position(0.5 + ($index * 0.25), 64.0, 0.5),
            ));
            self::assertInstanceOf(LlamaEntity::class, $spawn->entity);
            $llamas[] = $spawn->entity;
        }
        $llamas[0]->setLeashHolder(EntityUuid::random(), 900);

        $advance = new ReflectionMethod(WorldSimulation::class, 'advanceLlamaCaravans');
        $advance->invoke($simulation);

        $followers = 0;
        $leaders = [];
        foreach (array_slice($llamas, 1) as $llama) {
            $leader = $llama->getCaravanLeaderUniqueId();
            if ($leader === null) {
                continue;
            }
            ++$followers;
            self::assertArrayNotHasKey($leader, $leaders, 'A caravan leader acquired two followers.');
            $leaders[$leader] = true;
        }
        self::assertSame(9, $followers);

        $llamas[0]->setLeashHolder(null, null);
        $advance->invoke($simulation);
        foreach ($llamas as $llama) {
            self::assertNull($llama->getCaravanLeaderUniqueId());
        }
    }

    public function testLlamaSpitTargetsWildWolfAndEnforcesCooldown(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $llamaSpawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        $wolfSpawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::WOLF,
            SpawnCause::COMMAND,
            'world',
            new Position(6.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(LlamaEntity::class, $llamaSpawn->entity);
        self::assertInstanceOf(WolfEntity::class, $wolfSpawn->entity);
        $advance = new ReflectionMethod(WorldSimulation::class, 'advanceLlamaCombat');

        $first = $advance->invoke($simulation, $llamaSpawn->entity);
        self::assertIsArray($first);
        self::assertCount(1, $first);
        self::assertInstanceOf(ProjectileSpawned::class, $first[0]);
        self::assertSame(ProjectileType::LLAMA_SPIT, $first[0]->projectile->type);
        self::assertFalse($llamaSpawn->entity->canSpit());
        self::assertSame([], $advance->invoke($simulation, $llamaSpawn->entity));
    }

    public function testCancelledLlamaSpitLeavesNoProjectileCooldownOrPostEvent(): void
    {
        $dispatcher = self::dispatcher();
        $preEvents = 0;
        $postEvents = 0;
        $dispatcher->register(
            'LandAnimalTest',
            ProjectileLaunchEvent::class,
            static function (ProjectileLaunchEvent $event) use (&$preEvents): void {
                ++$preEvents;
                $event->cancel();
            },
        );
        $dispatcher->register(
            'LandAnimalTest',
            ProjectileLaunchedEvent::class,
            static function () use (&$postEvents): void {
                ++$postEvents;
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $llama = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ))->entity;
        $wolf = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::WOLF,
            SpawnCause::COMMAND,
            'world',
            new Position(6.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(LlamaEntity::class, $llama);
        self::assertInstanceOf(WolfEntity::class, $wolf);

        $advance = new ReflectionMethod(WorldSimulation::class, 'advanceLlamaCombat');
        self::assertSame([], $advance->invoke($simulation, $llama));
        self::assertSame(1, $preEvents);
        self::assertSame(0, $postEvents);
        self::assertTrue($llama->canSpit());
        $projectiles = (new ReflectionProperty(WorldSimulation::class, 'projectiles'))->getValue($simulation);
        self::assertInstanceOf(ProjectileRegistry::class, $projectiles);
        self::assertSame([], $projectiles->all());
    }

    private static function dispatcher(): EventDispatcher
    {
        return new EventDispatcher(
            new LandAnimalLifecycleRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
    }
}

final class LandAnimalLifecycleRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'LandAnimalTest';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
