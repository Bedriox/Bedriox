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

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Closure;

/** Bounded ordinary-spawner scheduler; entity admission remains owned by EntitySpawnService. */
final readonly class MobSpawnerRuntime
{
    private const int FAILED_BATCH_RETRY_TICKS = 20;

    /** @var Closure(int, int): int */
    private Closure $randomInteger;

    /** @param null|Closure(int, int): int $randomInteger */
    public function __construct(
        private MobSpawnerEnvironment $environment,
        ?Closure $randomInteger = null,
    ) {
        $this->randomInteger = $randomInteger ?? static fn(int $minimum, int $maximum): int => random_int($minimum, $maximum);
    }

    public function tick(
        MobSpawnerState $state,
        string $worldName,
        BlockPosition $position,
    ): MobSpawnerTickResult {
        $configuration = $state->configuration;
        if (!$this->environment->hasEligiblePlayer(
            $worldName,
            $position,
            $configuration->requiredPlayerRange,
        )) {
            return new MobSpawnerTickResult($state->withActivity(false));
        }
        if (!$state->active) {
            if (!$this->environment->allowActivation($worldName, $position)) {
                return new MobSpawnerTickResult($state);
            }
            $state = $state->withActivity(true);
        }
        if ($state->remainingDelayTicks > 0) {
            return new MobSpawnerTickResult($state->withDelay($state->remainingDelayTicks - 1));
        }
        $entityType = $this->environment->entityType($configuration->entityIdentifier);
        if ($entityType === null) {
            return new MobSpawnerTickResult($state->withDelay($this->nextDelay($state)));
        }
        if ($this->environment->nearbyEntities(
            $worldName,
            $position,
            $configuration->entityIdentifier,
            $configuration->spawnRange,
        ) >= $configuration->maximumNearbyEntities) {
            return new MobSpawnerTickResult($state->withDelay($this->nextDelay($state)));
        }

        $spawned = 0;
        for ($attempted = 0; $attempted < $configuration->spawnCount; ++$attempted) {
            $spawnPosition = new Position(
                $position->x + 0.5 + $this->offset($configuration->spawnRange),
                $position->y + ($this->randomInteger)(-1, 1),
                $position->z + 0.5 + $this->offset($configuration->spawnRange),
            );
            if (!$this->environment->allowSpawn(
                $worldName,
                $position,
                $configuration->entityIdentifier,
                $spawnPosition,
            )) {
                continue;
            }
            $outcome = $this->environment->spawn(new EntitySpawnRequest(
                $entityType,
                SpawnCause::SPAWNER,
                $worldName,
                $spawnPosition,
                (float) ($this->randomInteger)(0, 359),
            ));
            if ($outcome->succeeded()) {
                ++$spawned;
            }
        }

        return new MobSpawnerTickResult(
            $state->withDelay(
                $spawned > 0
                    ? $this->nextDelay($state)
                    : min(self::FAILED_BATCH_RETRY_TICKS, $configuration->maximumDelayTicks),
            ),
            $spawned,
            $configuration->spawnCount,
        );
    }

    private function offset(int $range): float
    {
        return ($this->randomInteger)(-$range * 100, $range * 100) / 100.0;
    }

    private function nextDelay(MobSpawnerState $state): int
    {
        return ($this->randomInteger)(
            $state->configuration->minimumDelayTicks,
            $state->configuration->maximumDelayTicks,
        );
    }
}
