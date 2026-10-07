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
use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\EntityTransformReason;
use Bedriox\Api\Entity\Value\MooshroomStewEffect;
use Bedriox\Api\Entity\Value\MooshroomVariant;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityShearedEvent;
use Bedriox\Api\Event\Entity\EntityShearEvent;
use Bedriox\Api\Event\Entity\EntityTransformedEvent;
use Bedriox\Api\Event\Entity\EntityTransformEvent;
use Bedriox\Api\Event\Entity\GoatRamEvent;
use Bedriox\Api\Event\Entity\GoatRammedEvent;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\GoatEntity;
use Bedriox\Server\Entity\Vanilla\MooshroomEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
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
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\VanillaBlockStates;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use Throwable;

final class GoatMooshroomQualificationTest extends TestCase
{
    public function testGoatRamsLivingMobAndPublishesCommittedDamage(): void
    {
        $dispatcher = self::dispatcher();
        $rammedTargets = [];
        $dispatcher->register('GoatMooshroomTest', GoatRammedEvent::class, static function (GoatRammedEvent $event) use (&$rammedTargets): void {
            self::assertInstanceOf(LivingEntity::class, $event->target);
            $rammedTargets[] = [$event->target->getType(), $event->target->getHealth()];
        });
        [$simulation] = self::simulation(new PluginGameplayEventBridge($dispatcher));
        $goat = self::spawnGoat($simulation, new Position(4.5, 64.0, 0.5));
        $cow = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(5.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(CowEntity::class, $cow);
        $goat->beginRam($cow->getUniqueId(), false);

        for ($tick = 0; $tick < 4; ++$tick) {
            $simulation->tick();
        }

        self::assertSame(8.0, $cow->getHealth());
        self::assertFalse($goat->isRamming());
        self::assertSame([[VanillaEntityType::COW, 8.0]], $rammedTargets);
        self::assertGreaterThan(0.0, $cow->getMotion()->x);
        self::assertSame(0.35, $cow->getMotion()->y);
    }

    public function testCancelledAutonomousGoatRamDoesNotStartOrDamageClosestMob(): void
    {
        $dispatcher = self::dispatcher();
        $preTargets = [];
        $postEvents = 0;
        $dispatcher->register('GoatMooshroomTest', GoatRamEvent::class, static function (GoatRamEvent $event) use (&$preTargets): void {
            self::assertInstanceOf(LivingEntity::class, $event->target);
            $preTargets[] = $event->target->getType();
            $event->cancel();
        });
        $dispatcher->register('GoatMooshroomTest', GoatRammedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation] = self::simulation(new PluginGameplayEventBridge($dispatcher));
        $goat = self::spawnGoat($simulation, new Position(8.5, 64.0, 0.5));
        $cow = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(9.0, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(CowEntity::class, $cow);

        for ($tick = 0; $tick < 210; ++$tick) {
            $simulation->tick();
        }

        self::assertSame([VanillaEntityType::COW], $preTargets);
        self::assertSame(0, $postEvents);
        self::assertSame(10.0, $cow->getHealth());
        self::assertFalse($goat->isRamming());
        self::assertTrue($goat->canBeginRam());
    }

    public function testGoatRamTargetKindAndTimersRoundTripThroughPersistence(): void
    {
        $targetId = EntityUuid::random();
        $goat = new GoatEntity(
            EntityUuid::random(),
            81,
            'world',
            new Position(0.5, 64.0, 0.5),
        );
        $goat->beginRam($targetId, false);

        $restored = new GoatEntity(
            EntityUuid::random(),
            82,
            'world',
            new Position(0.5, 64.0, 0.5),
        );
        $restored->restorePersistenceState(
            $goat->persistenceVariant(),
            $goat->persistenceSchemaVersion(),
            $goat->persistenceData(),
        );

        self::assertTrue($restored->isRamming());
        self::assertSame($targetId, $restored->getRamTargetUniqueId());
        self::assertFalse($restored->isRamTargetPlayer());
        for ($tick = 0; $tick < 4; ++$tick) {
            $restored->advanceRamTimers(20);
            self::assertTrue($restored->isRamming());
        }
        $restored->advanceRamTimers(20);
        self::assertFalse($restored->isRamming());
        self::assertNull($restored->getRamTargetUniqueId());
        self::assertTrue($restored->isRamTargetPlayer());
        self::assertFalse($restored->canBeginRam());
    }

    public function testGoatHornDropCommitsExactlyOnceAfterHardBlockCollision(): void
    {
        [$simulation, $world, $states, $items, $playerId] = self::goatWorldSimulation();
        $world->setBlockState(2, 64, 0, $states->internalId(VanillaBlockStates::stone()));
        $goat = self::spawnGoat($simulation, new Position(3.5, 64.0, 0.5));
        $goat->beginRam($playerId);

        for ($tick = 0; $tick < 4; ++$tick) {
            $simulation->tick();
        }

        self::assertFalse($goat->hasLeftHorn());
        self::assertTrue($goat->hasRightHorn());
        self::assertFalse($goat->isRamming());
        self::assertCount(1, $items->all());
        self::assertSame('minecraft:goat_horn', $items->all()[0]->stack->identifier);
        self::assertSame(1, $items->all()[0]->stack->count);
        for ($tick = 0; $tick < 20; ++$tick) {
            $simulation->tick();
        }
        self::assertCount(1, $items->all());
    }

    public function testGoatRetainsHornWhenHardBlockDropCannotBeAdmitted(): void
    {
        $items = new ItemEntityRegistry(1, 5_000);
        $items->spawn(new InventoryStack('minecraft:stone', 1, 1), new Position(100.0, 64.0, 100.0));
        [$simulation, $world, $states, , $playerId] = self::goatWorldSimulation($items);
        $world->setBlockState(2, 64, 0, $states->internalId(VanillaBlockStates::oakLog()));
        $goat = self::spawnGoat($simulation, new Position(3.5, 64.0, 0.5));
        $goat->beginRam($playerId);

        for ($tick = 0; $tick < 4; ++$tick) {
            $simulation->tick();
        }

        self::assertTrue($goat->hasLeftHorn());
        self::assertTrue($goat->hasRightHorn());
        self::assertFalse($goat->isRamming());
        self::assertCount(1, $items->all());
        self::assertSame('minecraft:stone', $items->all()[0]->stack->identifier);
    }

    public function testGoatDoesNotLoseHornToUnqualifiedStoneNamedBlock(): void
    {
        [$simulation, $world, $states, $items, $playerId] = self::goatWorldSimulation();
        $world->setBlockState(2, 64, 0, $states->internalId(VanillaBlockStates::sandstone()));
        $goat = self::spawnGoat($simulation, new Position(3.5, 64.0, 0.5));
        $goat->beginRam($playerId);

        for ($tick = 0; $tick < 4; ++$tick) {
            $simulation->tick();
        }

        self::assertTrue($goat->hasLeftHorn());
        self::assertTrue($goat->hasRightHorn());
        self::assertTrue($goat->isRamming());
        self::assertSame([], $items->all());
    }

    public function testCancelledMooshroomShearIsACompleteNoOp(): void
    {
        $dispatcher = self::dispatcher();
        $postEvents = 0;
        $transformEvents = 0;
        $dispatcher->register('GoatMooshroomTest', EntityShearEvent::class, static function (EntityShearEvent $event): void {
            self::assertSame('minecraft:shears', $event->tool->identifier);
            self::assertSame('minecraft:red_mushroom', $event->getDrops()[0]->identifier);
            self::assertSame(5, $event->getDrops()[0]->count);
            $event->cancel();
        });
        $dispatcher->register('GoatMooshroomTest', EntityShearedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        $dispatcher->register('GoatMooshroomTest', EntityTransformEvent::class, static function () use (&$transformEvents): void {
            ++$transformEvents;
        });

        [$simulation, $commands, $playerId, $items] = self::simulation(new PluginGameplayEventBridge($dispatcher));
        $mooshroom = self::spawnMooshroom($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1, damage: 7));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $mooshroom->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertSame($mooshroom, $simulation->entityRuntime()->registry()->getByRuntimeId($mooshroom->getRuntimeId()));
        self::assertSame(7, $player->inventory->selectedStack()?->damage);
        self::assertSame([], $items->all());
        self::assertSame(0, $postEvents);
        self::assertSame(0, $transformEvents);
    }

    public function testCancelledMooshroomTransformationDoesNotWearShearsOrDropItems(): void
    {
        $dispatcher = self::dispatcher();
        $shearPreEvents = 0;
        $transformPostEvents = 0;
        $dispatcher->register('GoatMooshroomTest', EntityShearEvent::class, static function () use (&$shearPreEvents): void {
            ++$shearPreEvents;
        });
        $dispatcher->register('GoatMooshroomTest', EntityTransformEvent::class, static function (EntityTransformEvent $event): void {
            self::assertSame(EntityTransformReason::SHEARING, $event->reason);
            self::assertSame(VanillaEntityType::COW, $event->targetType());
            $event->cancel();
        });
        $dispatcher->register('GoatMooshroomTest', EntityTransformedEvent::class, static function () use (&$transformPostEvents): void {
            ++$transformPostEvents;
        });

        [$simulation, $commands, $playerId, $items] = self::simulation(new PluginGameplayEventBridge($dispatcher));
        $mooshroom = self::spawnMooshroom($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1, damage: 13));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $mooshroom->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertSame(1, $shearPreEvents);
        self::assertSame(0, $transformPostEvents);
        self::assertSame($mooshroom, $simulation->entityRuntime()->registry()->getByRuntimeId($mooshroom->getRuntimeId()));
        self::assertSame(13, $player->inventory->selectedStack()?->damage);
        self::assertSame([], $items->all());
    }

    public function testInvalidNonLivingMooshroomTransformationCannotPartiallySpawn(): void
    {
        $dispatcher = self::dispatcher();
        $dispatcher->register('GoatMooshroomTest', EntityTransformEvent::class, static function (EntityTransformEvent $event): void {
            $event->setTargetType(VanillaEntityType::LEASH_KNOT);
        });
        [$simulation, $commands, $playerId, $items] = self::simulation(new PluginGameplayEventBridge($dispatcher));
        $mooshroom = self::spawnMooshroom($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1, damage: 9));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $mooshroom->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertSame($mooshroom, $simulation->entityRuntime()->registry()->getByRuntimeId($mooshroom->getRuntimeId()));
        self::assertSame(9, $player->inventory->selectedStack()?->damage);
        self::assertSame([], $items->all());
        self::assertCount(0, array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn(object $entity): bool => $entity->getType() === VanillaEntityType::LEASH_KNOT,
        ));
    }

    public function testMooshroomLightningConversionClearsIncompatibleStewAndPersistsVariant(): void
    {
        $mooshroom = new MooshroomEntity(
            EntityUuid::random(),
            83,
            'world',
            new Position(0.5, 64.0, 0.5),
            variant: MooshroomVariant::BROWN,
            stewEffect: MooshroomStewEffect::ALLIUM,
        );
        $revision = $mooshroom->presentationRevision();

        $mooshroom->struckByLightning();

        self::assertSame(MooshroomVariant::RED, $mooshroom->getVariant());
        self::assertNull($mooshroom->getStewEffect());
        self::assertGreaterThan($revision, $mooshroom->presentationRevision());
        $restored = new MooshroomEntity(
            EntityUuid::random(),
            84,
            'world',
            new Position(0.5, 64.0, 0.5),
        );
        $restored->restorePersistenceState(
            $mooshroom->persistenceVariant(),
            $mooshroom->persistenceSchemaVersion(),
            $mooshroom->persistenceData(),
        );
        self::assertSame(MooshroomVariant::RED, $restored->getVariant());
        self::assertNull($restored->getStewEffect());
        $restored->struckByLightning();
        self::assertSame(MooshroomVariant::BROWN, $restored->getVariant());
    }

    public function testMooshroomShearingPreservesPoseAndHealthAndCommitsEventsAfterMutation(): void
    {
        $dispatcher = self::dispatcher();
        $order = [];
        $transformHealth = null;
        $dispatcher->register('GoatMooshroomTest', EntityShearEvent::class, static function () use (&$order): void {
            $order[] = 'shear-pre';
        });
        $dispatcher->register('GoatMooshroomTest', EntityTransformEvent::class, static function () use (&$order): void {
            $order[] = 'transform-pre';
        });
        $dispatcher->register('GoatMooshroomTest', EntityShearedEvent::class, static function (EntityShearedEvent $event) use (&$order): void {
            self::assertSame(5, $event->drops[0]->count);
            $order[] = 'shear-post';
        });
        $dispatcher->register('GoatMooshroomTest', EntityTransformedEvent::class, static function (EntityTransformedEvent $event) use (&$order, &$transformHealth): void {
            $transformHealth = [$event->original->getHealth(), $event->transformed->getHealth()];
            $order[] = 'transform-post';
        });

        [$simulation, $commands, $playerId, $items] = self::simulation(new PluginGameplayEventBridge($dispatcher));
        $mooshroom = self::spawnMooshroom($simulation, new Position(1.5, 64.0, 2.5), 73.0, -12.0);
        $mooshroom->damage(4.0);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $mooshroom->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        $cows = array_values(array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn(object $entity): bool => $entity instanceof CowEntity,
        ));
        self::assertCount(1, $cows);
        $cow = $cows[0];
        self::assertEquals(new Position(1.5, 64.0, 2.5), $cow->internalPosition());
        self::assertSame(73.0, $cow->getYaw());
        self::assertSame(-12.0, $cow->getPitch());
        self::assertSame(6.0, $cow->getHealth());
        self::assertSame(1, $player->inventory->selectedStack()?->damage);
        self::assertSame(['shear-pre', 'transform-pre', 'shear-post', 'transform-post'], $order);
        self::assertSame([6.0, 6.0], $transformHealth);
        self::assertCount(1, $items->all());
        self::assertSame('minecraft:red_mushroom', $items->all()[0]->stack->identifier);
        self::assertSame(5, $items->all()[0]->stack->count);
    }

    public function testBrownMooshroomShearingDropsBrownMushroomsAndBreaksSpentShears(): void
    {
        [$simulation, $commands, $playerId, $items] = self::simulation();
        $mooshroom = self::spawnMooshroom($simulation);
        $mooshroom->setVariant(MooshroomVariant::BROWN);
        $mooshroom->setStewEffect(MooshroomStewEffect::WITHER_ROSE);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1, damage: 238));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $mooshroom->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertNull($player->inventory->selectedStack());
        self::assertNull($simulation->entityRuntime()->registry()->getByRuntimeId($mooshroom->getRuntimeId()));
        self::assertCount(1, array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn(object $entity): bool => $entity instanceof CowEntity,
        ));
        self::assertCount(1, $items->all());
        self::assertSame('minecraft:brown_mushroom', $items->all()[0]->stack->identifier);
        self::assertSame(5, $items->all()[0]->stack->count);
    }

    public function testMooshroomShearingDoesNotPartiallyCommitWhenDropCapacityIsExhausted(): void
    {
        $items = new ItemEntityRegistry(1, 5_000);
        $items->spawn(new InventoryStack('minecraft:stone', 1, 1), new Position(100.0, 64.0, 100.0));
        [$simulation, $commands, $playerId] = self::simulation(itemEntities: $items);
        $mooshroom = self::spawnMooshroom($simulation);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1, damage: 5));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $mooshroom->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertSame($mooshroom, $simulation->entityRuntime()->registry()->getByRuntimeId($mooshroom->getRuntimeId()));
        self::assertSame(5, $player->inventory->selectedStack()?->damage);
        self::assertCount(1, $items->all());
        self::assertCount(0, array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn(object $entity): bool => $entity instanceof CowEntity,
        ));
    }

    public function testSuspiciousStewTransactionRemainsIntactWhenResultCannotBeAdmitted(): void
    {
        $items = new ItemEntityRegistry(1, 5_000);
        $items->spawn(new InventoryStack('minecraft:stone', 1, 1), new Position(100.0, 64.0, 100.0));
        [$simulation, $commands, $playerId] = self::simulation(itemEntities: $items);
        $mooshroom = self::spawnMooshroom($simulation);
        $mooshroom->setVariant(MooshroomVariant::BROWN);
        $mooshroom->setStewEffect(MooshroomStewEffect::BLUE_ORCHID);
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:bowl', 2, 1));
        for ($slot = 1; $slot < 36; ++$slot) {
            $player->inventory->replaceSlot($slot, new InventoryStack('minecraft:stone', 64, $slot + 1));
        }

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $mooshroom->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();

        self::assertSame(MooshroomStewEffect::BLUE_ORCHID, $mooshroom->getStewEffect());
        self::assertSame('minecraft:bowl', $player->inventory->selectedStack()?->identifier);
        self::assertSame(2, $player->inventory->selectedStack()->count);
        self::assertCount(1, $items->all());
    }

    /** @return array{WorldSimulation, SimulationCommandFactory, string, ItemEntityRegistry} */
    private static function simulation(
        ?PluginGameplayEventBridge $bridge = null,
        ?ItemEntityRegistry $itemEntities = null,
    ): array {
        $playerId = EntityUuid::random();
        $itemEntities ??= new ItemEntityRegistry();
        $simulation = new WorldSimulation(
            pluginEvents: $bridge,
            itemCatalog: ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry()),
            itemEntities: $itemEntities,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', $playerId, 'Player')));
        $simulation->tick();

        return [$simulation, $commands, $playerId, $itemEntities];
    }

    private static function spawnMooshroom(
        WorldSimulation $simulation,
        ?Position $position = null,
        float $yaw = 0.0,
        float $pitch = 0.0,
    ): MooshroomEntity {
        $outcome = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MOOSHROOM,
            SpawnCause::COMMAND,
            'world',
            $position ?? new Position(1.5, 64.0, 0.5),
            $yaw,
            $pitch,
        ));
        self::assertInstanceOf(MooshroomEntity::class, $outcome->entity);

        return $outcome->entity;
    }

    private static function spawnGoat(WorldSimulation $simulation, Position $position): GoatEntity
    {
        $outcome = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::GOAT,
            SpawnCause::COMMAND,
            'world',
            $position,
        ));
        self::assertInstanceOf(GoatEntity::class, $outcome->entity);

        return $outcome->entity;
    }

    /** @return array{WorldSimulation, World, BlockStateRegistry, ItemEntityRegistry, string} */
    private static function goatWorldSimulation(?ItemEntityRegistry $items = null): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('world', 91),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->chunk(new ChunkPosition(0, 0));
        $items ??= new ItemEntityRegistry();
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette($states, $generation),
            itemEntities: $items,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $playerId = EntityUuid::random();
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', $playerId, 'Player')));
        $simulation->tick();

        return [$simulation, $world, $states, $items, $playerId];
    }

    private static function dispatcher(): EventDispatcher
    {
        return new EventDispatcher(
            new GoatMooshroomRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
    }
}

final class GoatMooshroomRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'GoatMooshroomTest';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
