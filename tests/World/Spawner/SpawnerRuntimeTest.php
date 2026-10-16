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

namespace Bedriox\Server\Tests\World\Spawner;

use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\World\Spawner\SpawnerConfiguration;
use Bedriox\Api\World\Spawner\TrialSpawnerConfiguration;
use Bedriox\Api\World\Spawner\TrialSpawnerPhase;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\Spawner\SpawnerSpawnSiteAdmission;
use Bedriox\Server\World\BlockEntity\TrialSpawnerBlockEntity;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Spawner\MobSpawnerEnvironment;
use Bedriox\Server\World\Spawner\MobSpawnerRuntime;
use Bedriox\Server\World\Spawner\MobSpawnerState;
use Bedriox\Server\World\Spawner\SpawnerSpawnSiteEnvironment;
use Bedriox\Server\World\Spawner\TrialSpawnerEnvironment;
use Bedriox\Server\World\Spawner\TrialSpawnerRuntime;
use Bedriox\Server\World\Spawner\TrialSpawnerState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SpawnerRuntimeTest extends TestCase
{
    public function testOrdinarySpawnerPausesWithoutPlayersAndResetsAfterBoundedSpawnBatch(): void
    {
        $environment = new TestMobSpawnerEnvironment();
        $runtime = new MobSpawnerRuntime($environment, static fn(int $minimum, int $maximum): int => $minimum);
        $state = new MobSpawnerState(new SpawnerConfiguration('minecraft:cow', 2, 4, 3), 1);
        $position = new BlockPosition(4, 64, 4);

        $paused = $runtime->tick($state, 'world', $position);
        self::assertSame(1, $paused->state->remainingDelayTicks);
        self::assertFalse($paused->state->active);

        $environment->playerInRange = true;
        $countdown = $runtime->tick($paused->state, 'world', $position);
        self::assertTrue($countdown->state->active);
        self::assertSame(0, $countdown->state->remainingDelayTicks);

        $spawned = $runtime->tick($countdown->state, 'world', $position);
        self::assertSame(3, $spawned->attempted);
        self::assertSame(3, $spawned->spawned);
        self::assertSame(2, $spawned->state->remainingDelayTicks);
        self::assertCount(3, $environment->requests);
        self::assertSame(SpawnCause::SPAWNER, $environment->requests[0]->cause);
    }

    public function testOrdinarySpawnerHonorsActivationSpawnAndNearbyAdmission(): void
    {
        $environment = new TestMobSpawnerEnvironment();
        $environment->playerInRange = true;
        $environment->activationAllowed = false;
        $runtime = new MobSpawnerRuntime($environment, static fn(int $minimum, int $maximum): int => $minimum);
        $state = new MobSpawnerState(new SpawnerConfiguration('minecraft:cow', 2, 4, 2, 1), 0);

        $cancelled = $runtime->tick($state, 'world', new BlockPosition(0, 64, 0));
        self::assertFalse($cancelled->state->active);
        self::assertSame(0, $cancelled->attempted);

        $environment->activationAllowed = true;
        $environment->nearby = 1;
        $capped = $runtime->tick($state, 'world', new BlockPosition(0, 64, 0));
        self::assertSame(2, $capped->state->remainingDelayTicks);
        self::assertSame(0, $capped->attempted);

        $environment->nearby = 0;
        $environment->spawnAllowed = false;
        $rejected = $runtime->tick(new MobSpawnerState($state->configuration, 0, true), 'world', new BlockPosition(0, 64, 0));
        self::assertSame(2, $rejected->attempted);
        self::assertSame(0, $rejected->spawned);
        self::assertSame(4, $rejected->state->remainingDelayTicks);

        $immediateRetry = $runtime->tick($rejected->state, 'world', new BlockPosition(0, 64, 0));
        self::assertSame(0, $immediateRetry->attempted);
        self::assertSame(3, $immediateRetry->state->remainingDelayTicks);
    }

    public function testSpawnerStatesRoundTripAndRejectMalformedInput(): void
    {
        $ordinary = new MobSpawnerState(new SpawnerConfiguration('minecraft:zombie', 20, 80, 4, 6, 16, 4), 17, true, 9);
        self::assertEquals($ordinary, MobSpawnerState::fromArray($ordinary->toArray()));

        $trial = new TrialSpawnerState(
            new TrialSpawnerConfiguration('minecraft:bogged', 40, 30, 3, 1, 2, 400, 14),
            TrialSpawnerPhase::ACTIVE,
            ['player-a'],
            ['entity-a'],
            1,
            100,
            0,
            true,
            7,
        );
        self::assertEquals($trial, TrialSpawnerState::fromArray($trial->toArray()));

        $this->expectException(InvalidArgumentException::class);
        MobSpawnerState::fromArray(array_replace($ordinary->toArray(), ['spawn_count' => 100]));
    }

    public function testTrialSpawnerCompletesRewardsAndCooldownWithoutDuplicatingParticipants(): void
    {
        $environment = new TestTrialSpawnerEnvironment();
        $runtime = new TrialSpawnerRuntime($environment, static fn(int $minimum, int $maximum): int => $minimum);
        $configuration = new TrialSpawnerConfiguration('minecraft:cow', 2, 2, 1, 1, 1, 20, 14);
        $position = new BlockPosition(8, 64, 8);
        $state = TrialSpawnerState::initial($configuration);

        $environment->eligible = ['player-a', 'player-a'];
        $this->expectException(\UnexpectedValueException::class);
        $runtime->tick($state, 'world', $position, 1);
    }

    public function testTrialSpawnerRunsOneBoundedRestartSafeEncounter(): void
    {
        $environment = new TestTrialSpawnerEnvironment();
        $runtime = new TrialSpawnerRuntime($environment, static fn(int $minimum, int $maximum): int => $minimum);
        $configuration = new TrialSpawnerConfiguration('minecraft:cow', 2, 2, 1, 1, 1, 20, 14);
        $position = new BlockPosition(8, 64, 8);
        $state = TrialSpawnerState::initial($configuration, true);
        $environment->eligible = ['player-a', 'player-b'];

        $state = $runtime->tick($state, 'world', $position, 1)->state;
        self::assertSame(TrialSpawnerPhase::WAITING_FOR_PLAYERS, $state->phase);
        self::assertSame(['player-a', 'player-b'], $state->participantIds);
        self::assertSame(2, $state->remainingActionTicks);

        $state = $runtime->tick(TrialSpawnerState::fromArray($state->toArray()), 'world', $position, 3)->state;
        self::assertSame(TrialSpawnerPhase::ACTIVE, $state->phase);

        $first = $runtime->tick($state, 'world', $position, 3);
        self::assertTrue($first->spawned);
        self::assertSame(1, $first->state->spawnedCount);
        $environment->active = [];

        $second = $runtime->tick($first->state, 'world', $position, 5);
        self::assertTrue($second->spawned);
        self::assertSame(2, $second->state->spawnedCount);
        $environment->active = [];

        $completed = $runtime->tick($second->state, 'world', $position, 7);
        self::assertTrue($completed->completed);
        self::assertSame(TrialSpawnerPhase::EJECTING_REWARDS, $completed->state->phase);
        self::assertSame([['player-a', 'player-b']], $environment->completions);

        $rewardOne = $runtime->tick($completed->state, 'world', $position, 7);
        self::assertTrue($rewardOne->rewarded);
        $rewardTwo = $runtime->tick($rewardOne->state, 'world', $position, 27);
        self::assertTrue($rewardTwo->rewarded);
        self::assertSame(['player-a', 'player-b'], $environment->rewards);

        $cooldown = $runtime->tick($rewardTwo->state, 'world', $position, 47);
        self::assertSame(TrialSpawnerPhase::COOLDOWN, $cooldown->state->phase);
        $reset = $runtime->tick($cooldown->state, 'world', $position, 67);
        self::assertSame(TrialSpawnerPhase::INACTIVE, $reset->state->phase);
        self::assertSame([], $reset->state->participantIds);
        self::assertFalse($reset->state->ominous);
    }

    public function testTrialSpawnerPersistsRelativeDeadlineAcrossRuntimeRestart(): void
    {
        $environment = new TestTrialSpawnerEnvironment();
        $runtime = new TrialSpawnerRuntime($environment, static fn(int $minimum, int $maximum): int => $minimum);
        $position = new BlockPosition(8, 64, 8);
        $environment->eligible = ['player-a'];

        $waiting = $runtime->tick(
            TrialSpawnerState::initial(new TrialSpawnerConfiguration('minecraft:cow', 40, 20, 1, 0, 1, 200, 14)),
            'world',
            $position,
        )->state;
        self::assertSame(40, $waiting->remainingActionTicks);

        $persisted = TrialSpawnerState::fromArray($waiting->toArray());
        $afterRestart = (new TrialSpawnerRuntime($environment))->tick($persisted, 'world', $position, 1)->state;
        self::assertSame(39, $afterRestart->remainingActionTicks);
        self::assertSame(TrialSpawnerPhase::WAITING_FOR_PLAYERS, $afterRestart->phase);
    }

    public function testPlacedTrialSpawnerDefaultsToZombieAndUsesThatTypeForItsSpawnRequest(): void
    {
        $environment = new TestTrialSpawnerEnvironment();
        $environment->eligible = ['player-a'];
        $runtime = new TrialSpawnerRuntime($environment, static fn(int $minimum, int $maximum): int => $minimum);
        $position = new BlockPosition(2, 64, 2);
        $state = TrialSpawnerBlockEntity::create($position)->state;

        self::assertSame('minecraft:zombie', $state->configuration->entityIdentifier);
        $state = $runtime->tick($state, 'world', $position)->state;
        $state = $runtime->tick($state, 'world', $position, $state->remainingActionTicks)->state;
        $spawn = $runtime->tick($state, 'world', $position);

        self::assertTrue($spawn->spawned);
        self::assertCount(1, $environment->requests);
        self::assertSame(VanillaEntityType::ZOMBIE, $environment->requests[0]->type);
    }

    public function testSpawnSiteAdmissionRunsBeforeEventsForLoadedCollisionFreeSpeciesSites(): void
    {
        $environment = new TestSpawnerSpawnSiteEnvironment();
        $admission = new SpawnerSpawnSiteAdmission($environment, EntityDefinitionRegistry::baseline());
        $position = new Position(0.5, 64.0, 0.5);

        self::assertTrue($admission->allows('world', 'minecraft:cow', $position, false));
        $environment->light = 15;
        self::assertFalse($admission->allows('world', 'minecraft:zombie', $position, false));
        self::assertTrue($admission->allows('world', 'minecraft:zombie', $position, true));
        self::assertFalse($admission->allows('world', 'minecraft:cod', $position, false));
        self::assertFalse($admission->allows('world', 'minecraft:strider', $position, false));
        $environment->medium = NaturalSpawnMedium::WATER;
        self::assertTrue($admission->allows('world', 'minecraft:cod', $position, false));
        self::assertFalse($admission->allows('world', 'minecraft:cow', $position, false));
        $environment->loaded = false;
        self::assertFalse($admission->allows('world', 'minecraft:cod', $position, false));
        $environment->loaded = true;
        $environment->collisionFree = false;
        self::assertFalse($admission->allows('world', 'minecraft:cod', $position, false));
    }
}

final class TestMobSpawnerEnvironment implements MobSpawnerEnvironment
{
    public bool $playerInRange = false;
    public bool $activationAllowed = true;
    public bool $spawnAllowed = true;
    public int $nearby = 0;

    /** @var list<EntitySpawnRequest> */
    public array $requests = [];

    public function entityType(string $identifier): ?EntityType
    {
        return VanillaEntityType::tryFrom($identifier);
    }

    public function hasEligiblePlayer(string $worldName, BlockPosition $position, int $range): bool
    {
        return $this->playerInRange;
    }

    public function nearbyEntities(string $worldName, BlockPosition $position, string $identifier, int $range): int
    {
        return $this->nearby;
    }

    public function allowActivation(string $worldName, BlockPosition $position): bool
    {
        return $this->activationAllowed;
    }

    public function allowSpawn(string $worldName, BlockPosition $spawner, string $identifier, Position $position): bool
    {
        return $this->spawnAllowed;
    }

    public function spawn(EntitySpawnRequest $request): EntitySpawnOutcome
    {
        $this->requests[] = $request;

        return EntitySpawnOutcome::success(new CowEntity(
            EntityUuid::random(),
            count($this->requests),
            $request->worldName,
            $request->position,
        ));
    }
}

final class TestTrialSpawnerEnvironment implements TrialSpawnerEnvironment
{
    /** @var list<EntitySpawnRequest> */
    public array $requests = [];

    /** @var list<string> */
    public array $eligible = [];

    /** @var list<string> */
    public array $active = [];

    /** @var list<string> */
    public array $rewards = [];

    /** @var list<list<string>> */
    public array $completions = [];

    private int $runtimeId = 0;

    public function entityType(string $identifier): ?EntityType
    {
        return VanillaEntityType::tryFrom($identifier);
    }

    public function eligibleParticipantIds(string $worldName, BlockPosition $position, int $range): array
    {
        return $this->eligible;
    }

    /**
     * @param list<string> $entityIds
     * @return list<string>
     */
    public function activeEntityIds(array $entityIds): array
    {
        return array_values(array_intersect($entityIds, $this->active));
    }

    public function allowActivation(string $worldName, BlockPosition $position, bool $ominous): bool
    {
        return true;
    }

    public function allowPhaseChange(
        string $worldName,
        BlockPosition $position,
        TrialSpawnerPhase $oldPhase,
        TrialSpawnerPhase $newPhase,
        bool $ominous,
    ): bool {
        return true;
    }

    public function allowSpawn(
        string $worldName,
        BlockPosition $spawner,
        string $identifier,
        Position $position,
        bool $ominous,
    ): bool {
        return true;
    }

    public function spawn(EntitySpawnRequest $request): EntitySpawnOutcome
    {
        $this->requests[] = $request;
        ++$this->runtimeId;
        $entity = new CowEntity(EntityUuid::random(), $this->runtimeId, $request->worldName, $request->position);
        $this->active[] = $entity->getUniqueId();

        return EntitySpawnOutcome::success($entity);
    }

    public function ejectReward(string $worldName, BlockPosition $position, string $participantId, bool $ominous): bool
    {
        $this->rewards[] = $participantId;

        return true;
    }

    public function completed(string $worldName, BlockPosition $position, array $participantIds, bool $ominous): void
    {
        $this->completions[] = $participantIds;
    }
}

final class TestSpawnerSpawnSiteEnvironment implements SpawnerSpawnSiteEnvironment
{
    public bool $loaded = true;
    public bool $stable = true;
    public bool $collisionFree = true;
    public NaturalSpawnMedium $medium = NaturalSpawnMedium::GROUND;
    public int $light = 0;

    public function isChunkLoaded(string $worldName, ChunkPosition $chunk): bool
    {
        return $worldName === 'world' && $this->loaded;
    }

    public function isChunkStable(string $worldName, ChunkPosition $chunk): bool
    {
        return $worldName === 'world' && $this->stable;
    }

    public function isCollisionFree(string $worldName, EntityType $type, Position $position): bool
    {
        return $worldName === 'world' && $this->collisionFree;
    }

    public function medium(string $worldName, Position $position): NaturalSpawnMedium
    {
        return $this->medium;
    }

    public function lightLevel(string $worldName, Position $position): int
    {
        return $this->light;
    }
}
