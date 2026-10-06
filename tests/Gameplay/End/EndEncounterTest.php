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

namespace Bedriox\Server\Tests\Gameplay\End;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Vanilla\End\EndCrystalEntity;
use Bedriox\Server\Entity\Vanilla\End\EnderDragonEntity;
use Bedriox\Server\Gameplay\End\EndEncounterCoordinator;
use Bedriox\Server\Gameplay\End\EndEncounterState;
use Bedriox\Server\Gameplay\End\EndEncounterStateRepository;
use Bedriox\Server\Gameplay\End\EndEncounterTarget;
use Bedriox\Server\Gameplay\End\EnderDragonAttackType;
use Bedriox\Server\Gameplay\End\EnderDragonPart;
use Bedriox\Server\Gameplay\End\EnderDragonPhase;
use Bedriox\Server\Gameplay\End\EndGatewayPlanner;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\EndWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class EndEncounterTest extends TestCase
{
    public function testVictoryBuildsACompleteActiveExitFountain(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $world = new World(
            new WorldMetadata('world', 42),
            new EndWorldGenerator(42, $states),
            new ChunkRepository(32),
        );
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $coordinator = new EndEncounterCoordinator(
            'world',
            42,
            $entities,
            $spawns->spawn(...),
            $world,
            $states,
        );
        $coordinator->tick(1);
        $dragon = array_values(array_filter(
            $entities->all(),
            static fn($entity): bool => $entity instanceof EnderDragonEntity,
        ))[0];
        $dragon->damage($dragon->getMaximumHealth());
        self::assertTrue($coordinator->confirmDragonDeath($dragon->getUniqueId()));
        self::assertTrue($coordinator->tick(2)->completed);

        self::assertSame('minecraft:bedrock', $states->state($world->blockStateAt(0, 68, 0))->identifier());
        self::assertSame('minecraft:end_stone', $states->state($world->blockStateAt(3, 68, 0))->identifier());
        self::assertSame('minecraft:bedrock', $states->state($world->blockStateAt(0, 69, 0))->identifier());
        self::assertSame('minecraft:end_portal', $states->state($world->blockStateAt(1, 69, 0))->identifier());
        self::assertSame('minecraft:bedrock', $states->state($world->blockStateAt(3, 69, 0))->identifier());
        self::assertSame('minecraft:air', $states->state($world->blockStateAt(1, 70, 0))->identifier());
        self::assertSame('minecraft:dragon_egg', $states->state($world->blockStateAt(0, 73, 0))->identifier());
    }

    public function testPublishedVictoryRepairsTheExitFountainOnceAfterRestart(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $world = new World(
            new WorldMetadata('world', 42),
            new EndWorldGenerator(42, $states),
            new ChunkRepository(32),
        );
        $store = new class implements TransientEntityPersistenceStore {
            public ?string $payload = null;

            public function loadTransientEntities(string $namespace): ?string
            {
                return $this->payload;
            }

            public function saveTransientEntities(string $namespace, ?string $payload): void
            {
                $this->payload = $payload;
            }
        };
        $repository = new EndEncounterStateRepository($store);
        $repository->save((new EndEncounterState())
            ->withDragon('defeated-dragon')
            ->confirmDragonDeath('defeated-dragon')
            ->prepareVictory(false)
            ->withVictoryStage(\Bedriox\Server\Gameplay\End\EndVictoryStage::PUBLISHED));
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $coordinator = new EndEncounterCoordinator(
            'world',
            42,
            $entities,
            $spawns->spawn(...),
            $world,
            $states,
            $repository,
        );

        self::assertTrue($coordinator->tick(1)->stateChanged);
        self::assertSame('minecraft:end_portal', $states->state($world->blockStateAt(1, 69, 0))->identifier());
        self::assertFalse($coordinator->tick(2)->stateChanged);
        self::assertSame(0, $entities->countByType(VanillaEntityType::ENDER_DRAGON));
    }

    public function testWorldSimulationDoesNotInvalidateTheServerAuthoredExitFountain(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $world = new World(
            new WorldMetadata('world', 42),
            new EndWorldGenerator(42, $states),
            new ChunkRepository(32),
            dimension: WorldDimension::END,
        );
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockStateRegistry: $states,
            worldId: 'world',
            dimension: WorldDimension::END,
        );
        $encounter = $simulation->endEncounter();
        self::assertNotNull($encounter);

        (new \ReflectionMethod($encounter, 'activateExitFountain'))->invoke($encounter, false);

        self::assertSame(
            'minecraft:end_portal',
            $states->state($world->blockStateAt(1, 69, 0))->identifier(),
        );
        self::assertSame(
            'minecraft:bedrock',
            $states->state($world->blockStateAt(0, 70, 0))->identifier(),
        );
    }

    public function testGatewayOrderAndPairingAreDeterministicAndComplete(): void
    {
        $first = EndGatewayPlanner::order(73);
        self::assertSame($first, EndGatewayPlanner::order(73));
        self::assertNotSame($first, EndGatewayPlanner::order(74));
        $sorted = $first;
        sort($sorted);
        self::assertSame(range(0, 19), $sorted);

        foreach (range(0, 19) as $slot) {
            $inner = EndGatewayPlanner::inner($slot);
            $outer = EndGatewayPlanner::outer($slot);
            self::assertEqualsWithDelta(96.0, hypot($inner->x, $inner->z), 1.0);
            self::assertEqualsWithDelta(1_024.0, hypot($outer->x, $outer->z), 1.0);
        }
    }

    public function testStatePersistenceRejectsUnknownSchemaAndRoundTrips(): void
    {
        $store = new class implements TransientEntityPersistenceStore {
            /** @var array<string, string> */
            public array $values = [];
            public function loadTransientEntities(string $namespace): ?string
            {
                return $this->values[$namespace] ?? null;
            }
            public function saveTransientEntities(string $namespace, ?string $payload): void
            {
                if ($payload === null) {
                    unset($this->values[$namespace]);
                } else {
                    $this->values[$namespace] = $payload;
                }
            }
        };
        $repository = new EndEncounterStateRepository($store);
        $state = (new EndEncounterState())->withDragon('dragon-1')->confirmDragonDeath('dragon-1')->prepareVictory(true);
        $repository->save($state);

        self::assertEquals($state, $repository->load());
        $store->values['end_encounter'] = '{"schema":999}';
        $this->expectException(\InvalidArgumentException::class);
        $repository->load();
    }

    public function testCoordinatorReconcilesDragonAndCommitsVictoryOnce(): void
    {
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $coordinator = new EndEncounterCoordinator(
            'world',
            42,
            $entities,
            $spawns->spawn(...),
        );

        $started = $coordinator->tick(1);
        self::assertTrue($started->dragonSpawned);
        self::assertSame(1, $entities->countByType(VanillaEntityType::ENDER_DRAGON));
        self::assertSame(10, $entities->countByType(VanillaEntityType::ENDER_CRYSTAL));
        $dragon = array_values(array_filter($entities->all(), static fn($entity): bool => $entity instanceof EnderDragonEntity))[0];
        $dragon->damage($dragon->getMaximumHealth());
        self::assertTrue($coordinator->confirmDragonDeath($dragon->getUniqueId()));

        $victory = $coordinator->tick(2);
        self::assertTrue($victory->completed);
        self::assertSame(0, $coordinator->state()->gatewayCount);
        self::assertTrue($coordinator->claimVictoryPublication());
        $coordinator->completeVictoryPublication();
        $entities->remove($dragon->getRuntimeId());
        for ($tick = 3; $tick <= 203; ++$tick) {
            self::assertFalse($coordinator->tick($tick)->dragonSpawned);
        }
        self::assertSame(0, $entities->countByType(VanillaEntityType::ENDER_DRAGON));
        self::assertTrue($coordinator->state()->dragonKilled);
        self::assertSame(0, $coordinator->state()->gatewayCount);
        self::assertFalse($coordinator->requestRespawn());
        foreach ([
            new Position(3.5, 70.0, 0.5), new Position(-2.5, 70.0, 0.5),
            new Position(0.5, 70.0, 3.5), new Position(0.5, 70.0, -2.5),
        ] as $position) {
            self::assertTrue($spawns->spawn(new EntitySpawnRequest(
                VanillaEntityType::ENDER_CRYSTAL,
                SpawnCause::ITEM,
                'world',
                $position,
            ))->succeeded());
        }
        self::assertTrue($coordinator->requestRespawn());
    }

    public function testMissingDragonCannotFabricateVictoryWithoutConfirmedDeath(): void
    {
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $coordinator = new EndEncounterCoordinator('world', 42, $entities, $spawns->spawn(...));
        $coordinator->tick(1);
        $dragon = array_values(array_filter(
            $entities->all(),
            static fn($entity): bool => $entity instanceof EnderDragonEntity,
        ))[0];
        $entities->remove($dragon->getRuntimeId());

        self::assertFalse($coordinator->tick(2)->completed);
        self::assertFalse($coordinator->state()->dragonKilled);
        self::assertSame(0, $coordinator->state()->gatewayCount);
    }

    public function testPreparedVictoryRecoversAndClaimedVictoryDoesNotRepublishAfterRestart(): void
    {
        $store = new class implements TransientEntityPersistenceStore {
            /** @var array<string, string> */
            public array $values = [];
            public function loadTransientEntities(string $namespace): ?string
            {
                return $this->values[$namespace] ?? null;
            }
            public function saveTransientEntities(string $namespace, ?string $payload): void
            {
                if ($payload === null) {
                    unset($this->values[$namespace]);
                } else {
                    $this->values[$namespace] = $payload;
                }
            }
        };
        $repository = new EndEncounterStateRepository($store);
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $coordinator = new EndEncounterCoordinator('world', 42, $entities, $spawns->spawn(...), repository: $repository);
        $coordinator->tick(1);
        $dragon = array_values(array_filter($entities->all(), static fn($entity): bool => $entity instanceof EnderDragonEntity))[0];
        self::assertTrue($coordinator->confirmDragonDeath($dragon->getUniqueId()));
        self::assertTrue($coordinator->tick(2)->completed);

        $restarted = new EndEncounterCoordinator('world', 42, $entities, $spawns->spawn(...), repository: $repository);
        self::assertTrue($restarted->tick(3)->completed);
        self::assertTrue($restarted->claimVictoryPublication());

        $claimedRestart = new EndEncounterCoordinator('world', 42, $entities, $spawns->spawn(...), repository: $repository);
        self::assertFalse($claimedRestart->tick(4)->completed);
        self::assertFalse($claimedRestart->claimVictoryPublication());
    }

    public function testRespawnRitualOwnershipSurvivesRestartAndAbortsWhenCrystalIsInvalidated(): void
    {
        $store = new class implements TransientEntityPersistenceStore {
            /** @var array<string, string> */
            public array $values = [];
            public function loadTransientEntities(string $namespace): ?string
            {
                return $this->values[$namespace] ?? null;
            }
            public function saveTransientEntities(string $namespace, ?string $payload): void
            {
                if ($payload === null) {
                    unset($this->values[$namespace]);
                } else {
                    $this->values[$namespace] = $payload;
                }
            }
        };
        $repository = new EndEncounterStateRepository($store);
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $repository->save((new EndEncounterState())->withDragon('dragon-1')
            ->confirmDragonDeath('dragon-1')->prepareVictory(false)
            ->withVictoryStage(\Bedriox\Server\Gameplay\End\EndVictoryStage::PUBLISHED));
        foreach ([
            new Position(3.5, 70.0, 0.5), new Position(-2.5, 70.0, 0.5),
            new Position(0.5, 70.0, 3.5), new Position(0.5, 70.0, -2.5),
        ] as $position) {
            $spawns->spawn(new EntitySpawnRequest(VanillaEntityType::ENDER_CRYSTAL, SpawnCause::ITEM, 'world', $position));
        }
        $coordinator = new EndEncounterCoordinator('world', 42, $entities, $spawns->spawn(...), repository: $repository);
        self::assertTrue($coordinator->requestRespawn());
        self::assertCount(4, $coordinator->state()->ritualCrystalUuids);

        $restarted = new EndEncounterCoordinator('world', 42, $entities, $spawns->spawn(...), repository: $repository);
        self::assertTrue($restarted->tick(1)->stateChanged);
        $owned = $entities->getByUniqueId($restarted->state()->ritualCrystalUuids[0]);
        self::assertInstanceOf(EndCrystalEntity::class, $owned);
        self::assertNotNull($restarted->destroyCrystal($owned->getRuntimeId()));
        self::assertSame(\Bedriox\Server\Gameplay\End\EnderDragonRespawnStage::NONE, $restarted->state()->respawnStage);
        self::assertSame([], $restarted->state()->ritualCrystalUuids);
    }

    public function testDragonCombatIsBoundedAndCrystalDestructionDoesNotRecreateIt(): void
    {
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $coordinator = new EndEncounterCoordinator(
            'world',
            42,
            $entities,
            $spawns->spawn(...),
            targets: static fn(): array => [new EndEncounterTarget('player-1', new Position(10.0, 76.0, 0.0))],
        );

        $coordinator->tick(1);
        self::assertTrue($coordinator->requestPhase(EnderDragonPhase::STRAFING));
        $combat = $coordinator->tick(40);
        self::assertCount(1, $combat->attacks);
        self::assertSame(EnderDragonAttackType::FIREBALL, $combat->attacks[0]->type);
        self::assertSame('player-1', $combat->attacks[0]->targetUuid);

        $crystal = array_values(array_filter(
            $entities->all(),
            static fn($entity): bool => $entity instanceof EndCrystalEntity,
        ))[0];
        $before = $entities->countByType(VanillaEntityType::ENDER_CRYSTAL);
        $explosion = $coordinator->destroyCrystal($crystal->getRuntimeId());
        self::assertNotNull($explosion);
        self::assertSame(6.0, $explosion->power);
        self::assertSame($before - 1, $entities->countByType(VanillaEntityType::ENDER_CRYSTAL));
        self::assertNull($coordinator->destroyCrystal($crystal->getRuntimeId()));

        $coordinator->tick(60);
        self::assertSame($before - 1, $entities->countByType(VanillaEntityType::ENDER_CRYSTAL));
    }

    public function testDragonPartsApplyTheirAuthoritativeDamageMultipliers(): void
    {
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $outcome = $spawns->spawn(new EntitySpawnRequest(
            VanillaEntityType::ENDER_DRAGON,
            SpawnCause::STRUCTURE,
            'world',
            new Position(0.0, 90.0, 0.0),
        ));
        self::assertInstanceOf(EnderDragonEntity::class, $outcome->entity);
        $dragon = $outcome->entity;

        self::assertSame(8.0, $dragon->damagePart(EnderDragonPart::HEAD, 8.0));
        self::assertSame(4.0, $dragon->damagePart(EnderDragonPart::BODY, 8.0));
        self::assertSame(2.0, $dragon->damagePart(EnderDragonPart::WING, 8.0));
    }
}
