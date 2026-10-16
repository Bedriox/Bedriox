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
use Bedriox\Api\World\Spawner\TrialSpawnerPhase;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Closure;

/** Bounded trial-spawner encounter state machine with restart-safe progress. */
final readonly class TrialSpawnerRuntime
{
    private const int REWARD_INTERVAL_TICKS = 20;
    private const int RETRY_INTERVAL_TICKS = 20;

    /** @var Closure(int, int): int */
    private Closure $randomInteger;

    /** @param null|Closure(int, int): int $randomInteger */
    public function __construct(
        private TrialSpawnerEnvironment $environment,
        ?Closure $randomInteger = null,
    ) {
        $this->randomInteger = $randomInteger ?? static fn(int $minimum, int $maximum): int => random_int($minimum, $maximum);
    }

    public function tick(
        TrialSpawnerState $state,
        string $worldName,
        BlockPosition $position,
        int $elapsedTicks = 1,
    ): TrialSpawnerTickResult {
        if ($elapsedTicks < 1 || $elapsedTicks > 1_200) {
            throw new \InvalidArgumentException('Trial-spawner elapsed ticks are outside its supported bounds.');
        }
        $eligible = $this->environment->eligibleParticipantIds(
            $worldName,
            $position,
            $state->configuration->requiredPlayerRange,
        );
        self::validateIdentities($eligible, 64, 'eligible participant');

        if ($state->phase === TrialSpawnerPhase::INACTIVE) {
            if ($eligible === [] || !$this->environment->allowActivation($worldName, $position, $state->ominous)
                || !$this->transitionAllowed($state, TrialSpawnerPhase::WAITING_FOR_PLAYERS, $worldName, $position)) {
                return new TrialSpawnerTickResult($state);
            }

            return new TrialSpawnerTickResult($state->begin($eligible));
        }

        $state = $state->elapse($elapsedTicks);

        if ($state->phase === TrialSpawnerPhase::COOLDOWN) {
            if ($state->remainingActionTicks > 0
                || !$this->transitionAllowed($state, TrialSpawnerPhase::INACTIVE, $worldName, $position)) {
                return new TrialSpawnerTickResult($state);
            }

            return new TrialSpawnerTickResult($state->reset());
        }

        if ($state->phase === TrialSpawnerPhase::WAITING_FOR_PLAYERS
            || $state->phase === TrialSpawnerPhase::ACTIVE) {
            $state = $state->withParticipants($eligible);
        }
        if ($state->phase === TrialSpawnerPhase::WAITING_FOR_PLAYERS) {
            if ($state->remainingActionTicks > 0
                || !$this->transitionAllowed($state, TrialSpawnerPhase::ACTIVE, $worldName, $position)) {
                return new TrialSpawnerTickResult($state);
            }

            return new TrialSpawnerTickResult($state->withPhase(TrialSpawnerPhase::ACTIVE, 0));
        }

        if ($state->phase === TrialSpawnerPhase::ACTIVE) {
            return $this->tickActive($state, $worldName, $position);
        }

        return $this->tickRewards($state, $worldName, $position);
    }

    private function tickActive(
        TrialSpawnerState $state,
        string $worldName,
        BlockPosition $position,
    ): TrialSpawnerTickResult {
        $active = $this->environment->activeEntityIds($state->activeSpawnedEntityIds);
        self::validateIdentities($active, 16, 'active spawned entity');
        $state = $state->withActiveEntities($active);
        $target = $state->configuration->totalMobsForParticipants(count($state->participantIds));
        if ($state->spawnedCount >= $target && $active === []) {
            if (!$this->transitionAllowed($state, TrialSpawnerPhase::EJECTING_REWARDS, $worldName, $position)) {
                return new TrialSpawnerTickResult($state);
            }
            $state = $state->withPhase(TrialSpawnerPhase::EJECTING_REWARDS, 0);
            $this->environment->completed($worldName, $position, $state->participantIds, $state->ominous);

            return new TrialSpawnerTickResult($state, completed: true);
        }
        if ($state->spawnedCount >= $target
            || count($active) >= $state->configuration->maximumSimultaneousMobs
            || $state->remainingActionTicks > 0) {
            return new TrialSpawnerTickResult($state);
        }
        $type = $this->environment->entityType($state->configuration->entityIdentifier);
        if ($type === null) {
            return new TrialSpawnerTickResult($state->withPhase(TrialSpawnerPhase::ACTIVE, self::RETRY_INTERVAL_TICKS));
        }
        $spawnPosition = new Position(
            $position->x + 0.5 + (($this->randomInteger)(-400, 400) / 100.0),
            $position->y + 1.0,
            $position->z + 0.5 + (($this->randomInteger)(-400, 400) / 100.0),
        );
        if (!$this->environment->allowSpawn(
            $worldName,
            $position,
            $state->configuration->entityIdentifier,
            $spawnPosition,
            $state->ominous,
        )) {
            return new TrialSpawnerTickResult($state->withPhase(TrialSpawnerPhase::ACTIVE, self::RETRY_INTERVAL_TICKS));
        }
        $outcome = $this->environment->spawn(new EntitySpawnRequest(
            $type,
            SpawnCause::SPAWNER,
            $worldName,
            $spawnPosition,
            (float) ($this->randomInteger)(0, 359),
        ));
        $spawnedEntity = $outcome->entity;
        if ($spawnedEntity === null) {
            return new TrialSpawnerTickResult($state->withPhase(TrialSpawnerPhase::ACTIVE, self::RETRY_INTERVAL_TICKS));
        }

        return new TrialSpawnerTickResult(
            $state->recordSpawn(
                $spawnedEntity->getUniqueId(),
                $state->configuration->spawnIntervalTicks,
            ),
            spawned: true,
        );
    }

    private function tickRewards(
        TrialSpawnerState $state,
        string $worldName,
        BlockPosition $position,
    ): TrialSpawnerTickResult {
        if ($state->remainingActionTicks > 0) {
            return new TrialSpawnerTickResult($state);
        }
        if ($state->rewardCursor >= count($state->participantIds)) {
            if (!$this->transitionAllowed($state, TrialSpawnerPhase::COOLDOWN, $worldName, $position)) {
                return new TrialSpawnerTickResult($state);
            }

            return new TrialSpawnerTickResult($state->withPhase(
                TrialSpawnerPhase::COOLDOWN,
                $state->configuration->cooldownTicks,
            ));
        }
        $participantId = $state->participantIds[$state->rewardCursor];
        if (!$this->environment->ejectReward($worldName, $position, $participantId, $state->ominous)) {
            return new TrialSpawnerTickResult($state->withPhase(
                TrialSpawnerPhase::EJECTING_REWARDS,
                self::RETRY_INTERVAL_TICKS,
            ));
        }

        return new TrialSpawnerTickResult(
            $state->recordReward(self::REWARD_INTERVAL_TICKS),
            rewarded: true,
        );
    }

    private function transitionAllowed(
        TrialSpawnerState $state,
        TrialSpawnerPhase $newPhase,
        string $worldName,
        BlockPosition $position,
    ): bool {
        return $this->environment->allowPhaseChange(
            $worldName,
            $position,
            $state->phase,
            $newPhase,
            $state->ominous,
        );
    }

    /** @param list<string> $identities */
    private static function validateIdentities(array $identities, int $maximum, string $kind): void
    {
        if (count($identities) > $maximum || count(array_unique($identities)) !== count($identities)) {
            throw new \UnexpectedValueException("Trial-spawner $kind identities must be a unique bounded list.");
        }
        foreach ($identities as $identity) {
            if ($identity === '' || strlen($identity) > 64 || preg_match('//u', $identity) !== 1) {
                throw new \UnexpectedValueException("Trial-spawner $kind identity is malformed.");
            }
        }
    }
}
