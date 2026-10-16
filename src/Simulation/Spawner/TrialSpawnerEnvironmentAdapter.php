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

namespace Bedriox\Server\Simulation\Spawner;

use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\World\Spawner\TrialSpawnerPhase;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Spawner\TrialSpawnerEnvironment;
use Closure;

/** Closure boundary between a trial encounter and its authoritative simulation services. */
final readonly class TrialSpawnerEnvironmentAdapter implements TrialSpawnerEnvironment
{
    /**
     * @param Closure(string): ?EntityType $entityType
     * @param Closure(string, BlockPosition, int): list<string> $participants
     * @param Closure(list<string>): list<string> $activeEntities
     * @param Closure(string, BlockPosition, bool): bool $activation
     * @param Closure(string, BlockPosition, TrialSpawnerPhase, TrialSpawnerPhase, bool): bool $phaseChange
     * @param Closure(string, BlockPosition, string, Position, bool): bool $spawnAdmission
     * @param Closure(EntitySpawnRequest): EntitySpawnOutcome $spawn
     * @param Closure(string, BlockPosition, string, bool): bool $reward
     * @param Closure(string, BlockPosition, list<string>, bool): void $completion
     */
    public function __construct(
        private Closure $entityType,
        private Closure $participants,
        private Closure $activeEntities,
        private Closure $activation,
        private Closure $phaseChange,
        private Closure $spawnAdmission,
        private Closure $spawn,
        private Closure $reward,
        private Closure $completion,
    ) {}

    public function entityType(string $identifier): ?EntityType
    {
        return ($this->entityType)($identifier);
    }

    public function eligibleParticipantIds(string $worldName, BlockPosition $position, int $range): array
    {
        return ($this->participants)($worldName, $position, $range);
    }

    public function activeEntityIds(array $entityIds): array
    {
        return ($this->activeEntities)($entityIds);
    }

    public function allowActivation(string $worldName, BlockPosition $position, bool $ominous): bool
    {
        return ($this->activation)($worldName, $position, $ominous);
    }

    public function allowPhaseChange(
        string $worldName,
        BlockPosition $position,
        TrialSpawnerPhase $oldPhase,
        TrialSpawnerPhase $newPhase,
        bool $ominous,
    ): bool {
        return ($this->phaseChange)($worldName, $position, $oldPhase, $newPhase, $ominous);
    }

    public function allowSpawn(
        string $worldName,
        BlockPosition $spawner,
        string $identifier,
        Position $position,
        bool $ominous,
    ): bool {
        return ($this->spawnAdmission)($worldName, $spawner, $identifier, $position, $ominous);
    }

    public function spawn(EntitySpawnRequest $request): EntitySpawnOutcome
    {
        return ($this->spawn)($request);
    }

    public function ejectReward(
        string $worldName,
        BlockPosition $position,
        string $participantId,
        bool $ominous,
    ): bool {
        return ($this->reward)($worldName, $position, $participantId, $ominous);
    }

    public function completed(
        string $worldName,
        BlockPosition $position,
        array $participantIds,
        bool $ominous,
    ): void {
        ($this->completion)($worldName, $position, $participantIds, $ominous);
    }
}
