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
use Bedriox\Server\Gameplay\End\EndVictoryStage;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class EndCrystalBeamLifecycleTest extends TestCase
{
    public function testActiveHealingCrystalTargetsDragonAndOtherBeamsRemainClear(): void
    {
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $coordinator = new EndEncounterCoordinator('world', 42, $entities, $spawns->spawn(...));
        $coordinator->tick(1);

        $dragon = self::first($entities, EnderDragonEntity::class);
        $dragon->damage(5.0);
        $coordinator->tick(10);

        $targeted = array_values(array_filter(
            $entities->all(),
            static fn($entity): bool => $entity instanceof EndCrystalEntity && $entity->beamTarget() !== null,
        ));
        self::assertCount(1, $targeted);
        self::assertEquals($dragon->internalPosition(), $targeted[0]->beamTarget());
    }

    public function testRespawnRitualTargetsArenaCenterAndProtectsAllFourCrystals(): void
    {
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());
        $state = (new EndEncounterState())
            ->withDragon('defeated-dragon')
            ->confirmDragonDeath('defeated-dragon')
            ->prepareVictory(false)
            ->withVictoryStage(EndVictoryStage::PUBLISHED);
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
        $repository->save($state);
        foreach ([
            new Position(3.5, 70.0, 0.5), new Position(-2.5, 70.0, 0.5),
            new Position(0.5, 70.0, 3.5), new Position(0.5, 70.0, -2.5),
        ] as $position) {
            $spawns->spawn(new EntitySpawnRequest(
                VanillaEntityType::ENDER_CRYSTAL,
                SpawnCause::ITEM,
                'world',
                $position,
            ));
        }
        $coordinator = new EndEncounterCoordinator(
            'world',
            42,
            $entities,
            $spawns->spawn(...),
            repository: $repository,
        );

        self::assertTrue($coordinator->requestRespawn());
        foreach ($coordinator->state()->ritualCrystalUuids as $uuid) {
            $crystal = $entities->getByUniqueId($uuid);
            self::assertInstanceOf(EndCrystalEntity::class, $crystal);
            self::assertEquals(new Position(0.0, 128.0, 0.0), $crystal->beamTarget());
            self::assertTrue($crystal->isInvulnerable());
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function first(EntityRegistry $entities, string $class): object
    {
        foreach ($entities->all() as $entity) {
            if ($entity instanceof $class) {
                return $entity;
            }
        }
        self::fail("Missing {$class}.");
    }
}
