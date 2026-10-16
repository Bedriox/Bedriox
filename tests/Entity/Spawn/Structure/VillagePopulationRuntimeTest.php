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

namespace Bedriox\Server\Tests\Entity\Spawn\Structure;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\Structure\VillagePopulationRuntime;
use Bedriox\Server\Entity\Spawn\Structure\VillagePopulationState;
use Bedriox\Server\Entity\Spawn\Structure\VillagePopulationStateRepository;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\VillagerEntity;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\Structure\PlacedStructureMarker;
use Bedriox\Server\World\Generation\Structure\StructureCoordinate;
use Bedriox\Server\World\Generation\Structure\StructureDirection;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;
use Bedriox\Server\World\Settlement\VillagePointOfInterestIndex;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class VillagePopulationRuntimeTest extends TestCase
{
    public function testFinalizedChunkAdmitsAndPersistsEachMarkerExactlyOnce(): void
    {
        $store = self::store();
        $repository = new VillagePopulationStateRepository($store);
        $requests = [];
        $nextRuntimeId = 1;
        $runtime = new VillagePopulationRuntime(
            $repository->load(),
            static function (EntitySpawnRequest $request) use (&$requests, &$nextRuntimeId): EntitySpawnOutcome {
                $requests[] = $request;

                return EntitySpawnOutcome::success(new CowEntity(
                    EntityUuid::random(),
                    $nextRuntimeId++,
                    $request->worldName,
                    $request->position,
                ));
            },
            static fn(ChunkPosition $chunk): array => self::markers(),
            $repository,
        );

        self::assertSame(2, $runtime->populateFinalizedChunk('world', new ChunkPosition(2, -1)));
        self::assertSame(0, $runtime->populateFinalizedChunk('world', new ChunkPosition(2, -1)));
        self::assertCount(2, $requests);
        self::assertSame(VanillaEntityType::VILLAGER, $requests[0]->type);
        self::assertSame(SpawnCause::STRUCTURE, $requests[0]->cause);
        self::assertSame('librarian:taiga', $requests[0]->variant);
        self::assertSame(32.5, $requests[0]->position->x);
        self::assertSame(-15.5, $requests[0]->position->z);
        self::assertSame(270.0, $requests[0]->yaw);
        self::assertSame(VanillaEntityType::IRON_GOLEM, $requests[1]->type);
        self::assertSame(180.0, $requests[1]->yaw);
        $reloaded = new VillagePopulationRuntime(
            $repository->load(),
            static fn(EntitySpawnRequest $request): EntitySpawnOutcome => throw new \LogicException('Claim was not restored.'),
            static fn(ChunkPosition $chunk): array => self::markers(),
            $repository,
        );
        self::assertSame(0, $reloaded->populateFinalizedChunk('world', new ChunkPosition(2, -1)));
    }

    public function testRejectedSpawnRemainsRetryable(): void
    {
        $attempts = 0;
        $runtime = new VillagePopulationRuntime(
            new VillagePopulationState(),
            static function (EntitySpawnRequest $request) use (&$attempts): EntitySpawnOutcome {
                ++$attempts;

                return EntitySpawnOutcome::failed('collision');
            },
            static fn(ChunkPosition $chunk): array => [self::markers()[0]],
        );

        self::assertSame(0, $runtime->populateFinalizedChunk('world', new ChunkPosition(2, -1)));
        self::assertSame(0, $runtime->populateFinalizedChunk('world', new ChunkPosition(2, -1)));
        self::assertSame(2, $attempts);
    }

    public function testVillagerWorkstationClaimUsesStableIdentityAndSurvivesPopulationReload(): void
    {
        $store = self::store();
        $repository = new VillagePopulationStateRepository($store);
        $points = new VillagePointOfInterestIndex();
        $spawned = null;
        $runtime = new VillagePopulationRuntime(
            $repository->load(),
            static function (EntitySpawnRequest $request) use (&$spawned): EntitySpawnOutcome {
                $spawned = new VillagerEntity(
                    self::assertSpawnUuid($request->uniqueId),
                    1,
                    $request->worldName,
                    $request->position,
                );
                $spawned->applySpawnVariant($request->variant ?? '');

                return EntitySpawnOutcome::success($spawned);
            },
            static fn(ChunkPosition $chunk): array => [self::markers()[0]],
            $repository,
            $points,
        );

        self::assertSame(1, $runtime->populateFinalizedChunk('world', new ChunkPosition(2, -1)));
        self::assertInstanceOf(VillagerEntity::class, $spawned);
        self::assertNotNull($spawned->getWorkstation());
        self::assertSame($spawned->getUniqueId(), $points->claimant($spawned->getWorkstation()));

        $reloadedPoints = new VillagePointOfInterestIndex();
        $reloaded = new VillagePopulationRuntime(
            $repository->load(),
            static fn(EntitySpawnRequest $request): EntitySpawnOutcome => throw new \LogicException('Claim was not restored.'),
            static fn(ChunkPosition $chunk): array => [self::markers()[0]],
            $repository,
            $reloadedPoints,
        );
        self::assertSame(0, $reloaded->populateFinalizedChunk('world', new ChunkPosition(2, -1)));
        self::assertNull($reloadedPoints->claimant($spawned->getWorkstation()));
        self::assertCount(1, $reloadedPoints->points());
    }

    public function testMalformedEmployedMarkerFailsBeforeSpawn(): void
    {
        $marker = self::markers()[0];
        $attributes = $marker->attributes;
        unset($attributes['workstation_y']);
        $runtime = new VillagePopulationRuntime(
            new VillagePopulationState(),
            static fn(EntitySpawnRequest $request): EntitySpawnOutcome => throw new \LogicException('Malformed marker reached spawn.'),
            static fn(ChunkPosition $chunk): array => [new PlacedStructureMarker(
                $marker->key,
                $marker->type,
                $marker->identifier,
                $marker->coordinate,
                $marker->direction,
                $attributes,
            )],
        );

        $this->expectException(UnexpectedValueException::class);
        $runtime->populateFinalizedChunk('world', new ChunkPosition(2, -1));
    }

    public function testMarkerBatchIsBounded(): void
    {
        $runtime = new VillagePopulationRuntime(
            new VillagePopulationState(),
            static fn(EntitySpawnRequest $request): EntitySpawnOutcome => throw new \LogicException('Oversized batch reached spawn.'),
            static fn(ChunkPosition $chunk): array => array_fill(0, 33, self::markers()[0]),
        );

        $this->expectException(UnexpectedValueException::class);
        $runtime->populateFinalizedChunk('world', new ChunkPosition(2, -1));
    }

    public function testStateRejectsDuplicateAndMalformedClaims(): void
    {
        $claim = 'village:' . str_repeat('a', 64);
        $this->expectException(InvalidArgumentException::class);
        new VillagePopulationState([$claim, $claim]);
    }

    /** @return list<PlacedStructureMarker> */
    private static function markers(): array
    {
        return [
            new PlacedStructureMarker(
                'villager_7',
                StructureMarkerType::EntitySpawn,
                'minecraft:villager_v2',
                new StructureCoordinate(32, 71, -16),
                StructureDirection::East,
                [
                    'marker_version' => 1,
                    'profession' => 'librarian',
                    'villager_type' => 'taiga',
                    'structure_x' => 40,
                    'structure_z' => -8,
                    'workstation' => 'minecraft:lectern',
                    'workstation_x' => 33,
                    'workstation_y' => 71,
                    'workstation_z' => -16,
                ],
            ),
            new PlacedStructureMarker(
                'meeting_golem',
                StructureMarkerType::EntitySpawn,
                'minecraft:iron_golem',
                new StructureCoordinate(40, 70, -8),
                StructureDirection::North,
                ['marker_version' => 1, 'structure_x' => 40, 'structure_z' => -8],
            ),
        ];
    }

    private static function store(): TransientEntityPersistenceStore
    {
        return new class implements TransientEntityPersistenceStore {
            /** @var array<string, string|null> */
            public array $payloads = [];

            public function loadTransientEntities(string $namespace): ?string
            {
                return $this->payloads[$namespace] ?? null;
            }

            public function saveTransientEntities(string $namespace, ?string $payload): void
            {
                $this->payloads[$namespace] = $payload;
            }
        };
    }

    private static function assertSpawnUuid(?string $uuid): string
    {
        self::assertNotNull($uuid);

        return $uuid;
    }
}
