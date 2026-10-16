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

namespace Bedriox\Server\World\Spawner;

use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\World\Spawner\TrialSpawnerPhase;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;

/** Authoritative world and reward boundary used by the trial-spawner state machine. */
interface TrialSpawnerEnvironment
{
    public function entityType(string $identifier): ?EntityType;

    /** @return list<string> Stable identities of eligible players currently in range. */
    public function eligibleParticipantIds(string $worldName, BlockPosition $position, int $range): array;

    /**
     * @param list<string> $entityIds
     * @return list<string>
     */
    public function activeEntityIds(array $entityIds): array;

    public function allowActivation(string $worldName, BlockPosition $position, bool $ominous): bool;

    public function allowPhaseChange(
        string $worldName,
        BlockPosition $position,
        TrialSpawnerPhase $oldPhase,
        TrialSpawnerPhase $newPhase,
        bool $ominous,
    ): bool;

    public function allowSpawn(
        string $worldName,
        BlockPosition $spawner,
        string $identifier,
        Position $position,
        bool $ominous,
    ): bool;

    public function spawn(EntitySpawnRequest $request): EntitySpawnOutcome;

    /** Atomically admits one server-selected reward for one participant. */
    public function ejectReward(
        string $worldName,
        BlockPosition $position,
        string $participantId,
        bool $ominous,
    ): bool;

    /** @param list<string> $participantIds */
    public function completed(
        string $worldName,
        BlockPosition $position,
        array $participantIds,
        bool $ominous,
    ): void;
}
