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
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityTameEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\FoxEntity;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SnifferEntity;
use Bedriox\Server\Entity\Vanilla\WolfEntity;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Player\InventoryStack;
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
use PHPUnit\Framework\TestCase;
use Throwable;

final class LandAnimalAtomicityAuditTest extends TestCase
{
    public function testCancelledSuccessfulTameDoesNotConsumeItemOrMutateWolf(): void
    {
        $dispatcher = self::dispatcher();
        $dispatcher->register(
            'LandAnimalAtomicityAudit',
            EntityTameEvent::class,
            static function (EntityTameEvent $event): void {
                $event->cancel();
            },
        );
        [$simulation, $commands, $playerId] = self::simulation(
            new LandAnimalAtomicityRandom(),
            new PluginGameplayEventBridge($dispatcher),
        );
        $wolf = self::spawn($simulation, VanillaEntityType::WOLF, WolfEntity::class, 1.5);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:bone', 2, 1));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $wolf->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertFalse($wolf->isTamed());
        self::assertSame(2, $player->inventory->selectedStack()?->count);
    }

    public function testCapacityBlockedShearingLeavesSheepAndToolUnchanged(): void
    {
        $items = new ItemEntityRegistry(1, 50_000);
        $items->spawn(new InventoryStack('minecraft:stone', 1, 1), new Position(20.0, 64.0, 20.0));
        [$simulation, $commands, $playerId] = self::simulation(new LandAnimalAtomicityRandom(), itemEntities: $items);
        $sheep = self::spawn($simulation, VanillaEntityType::SHEEP, SheepEntity::class, 1.5);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1, damage: 7));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $sheep->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertFalse($sheep->isSheared());
        self::assertSame(7, $player->inventory->selectedStack()?->damage);
        self::assertSame(1, $items->count());
    }

    public function testCapacityBlockedSnifferEggLeavesBothParentsReady(): void
    {
        $items = new ItemEntityRegistry(1, 50_000);
        $items->spawn(new InventoryStack('minecraft:stone', 1, 1), new Position(20.0, 64.0, 20.0));
        [$simulation, $commands, $playerId] = self::simulation(new LandAnimalAtomicityRandom(), itemEntities: $items);
        $first = self::spawn($simulation, VanillaEntityType::SNIFFER, SnifferEntity::class, 1.5);
        $second = self::spawn($simulation, VanillaEntityType::SNIFFER, SnifferEntity::class, 2.0);
        $first->setLoveTicks(BreedableAnimalEntity::MAXIMUM_LOVE_TICKS);
        $second->setLoveTicks(BreedableAnimalEntity::MAXIMUM_LOVE_TICKS);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:torchflower_seeds', 1, 1));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $first->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertTrue($first->isReadyToBreed());
        self::assertTrue($second->isReadyToBreed());
        self::assertSame(1, $player->inventory->selectedStack()?->count);
        self::assertSame(1, $items->count());
    }

    public function testFoxSwapDoesNotDeleteHeldItemWhenBothResultingDropsCannotFit(): void
    {
        $items = new ItemEntityRegistry(1, 50_000);
        $candidate = $items->spawn(
            new InventoryStack('minecraft:sweet_berries', 2, 1),
            new Position(0.5, 64.0, 0.5),
        );
        $simulation = new WorldSimulation(
            dropRandom: new LandAnimalAtomicityRandom(),
            itemEntities: $items,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $fox = self::spawn($simulation, VanillaEntityType::FOX, FoxEntity::class, 0.5);
        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ApiItemStack('minecraft:stone', 1));

        for ($tick = 0; $tick < 20; ++$tick) {
            $simulation->tick();
        }

        self::assertSame('minecraft:stone', $fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        self::assertSame(2, $items->get($candidate->runtimeEntityId)?->stack->count);
        self::assertSame(1, $items->count());
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

    /** @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function spawn(
        WorldSimulation $simulation,
        VanillaEntityType $type,
        string $class,
        float $x,
    ): object {
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            $type,
            SpawnCause::COMMAND,
            'world',
            new Position($x, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf($class, $entity);

        return $entity;
    }

    private static function dispatcher(): EventDispatcher
    {
        return new EventDispatcher(
            new LandAnimalAtomicityRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
    }
}

final class LandAnimalAtomicityRandom implements DropRandom
{
    private int $calls = 0;

    public function integer(int $minimum, int $maximum): int
    {
        ++$this->calls;
        return $minimum;
    }
}

final class LandAnimalAtomicityRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'LandAnimalAtomicityAudit';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
