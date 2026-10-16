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
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Spawner\MobSpawnerEnvironment;
use Closure;

/** Closure boundary between the deterministic spawner state machine and one authoritative simulation. */
final readonly class MobSpawnerEnvironmentAdapter implements MobSpawnerEnvironment
{
    /**
     * @param Closure(string): ?EntityType $entityType
     * @param Closure(string, BlockPosition, int): bool $eligiblePlayer
     * @param Closure(string, BlockPosition, string, int): int $nearbyEntities
     * @param Closure(string, BlockPosition): bool $activation
     * @param Closure(string, BlockPosition, string, Position): bool $spawnAdmission
     * @param Closure(EntitySpawnRequest): EntitySpawnOutcome $spawn
     */
    public function __construct(
        private Closure $entityType,
        private Closure $eligiblePlayer,
        private Closure $nearbyEntities,
        private Closure $activation,
        private Closure $spawnAdmission,
        private Closure $spawn,
    ) {}

    public function entityType(string $identifier): ?EntityType
    {
        return ($this->entityType)($identifier);
    }

    public function hasEligiblePlayer(string $worldName, BlockPosition $position, int $range): bool
    {
        return ($this->eligiblePlayer)($worldName, $position, $range);
    }

    public function nearbyEntities(string $worldName, BlockPosition $position, string $identifier, int $range): int
    {
        return ($this->nearbyEntities)($worldName, $position, $identifier, $range);
    }

    public function allowActivation(string $worldName, BlockPosition $position): bool
    {
        return ($this->activation)($worldName, $position);
    }

    public function allowSpawn(string $worldName, BlockPosition $spawner, string $identifier, Position $position): bool
    {
        return ($this->spawnAdmission)($worldName, $spawner, $identifier, $position);
    }

    public function spawn(EntitySpawnRequest $request): EntitySpawnOutcome
    {
        return ($this->spawn)($request);
    }
}
