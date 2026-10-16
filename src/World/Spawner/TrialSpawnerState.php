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

use Bedriox\Api\World\Spawner\TrialSpawnerConfiguration;
use Bedriox\Api\World\Spawner\TrialSpawnerPhase;
use InvalidArgumentException;

/** Immutable restart-safe trial-spawner encounter checkpoint. */
final readonly class TrialSpawnerState
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $participantIds
     * @param list<string> $activeSpawnedEntityIds
     */
    public function __construct(
        public TrialSpawnerConfiguration $configuration,
        public TrialSpawnerPhase $phase = TrialSpawnerPhase::INACTIVE,
        public array $participantIds = [],
        public array $activeSpawnedEntityIds = [],
        public int $spawnedCount = 0,
        public int $remainingActionTicks = 0,
        public int $rewardCursor = 0,
        public bool $ominous = false,
        public int $revision = 0,
    ) {
        self::identities($participantIds, 64, 'participant');
        self::identities($activeSpawnedEntityIds, 16, 'spawned entity');
        if ($spawnedCount < 0 || $spawnedCount > 256
            || $remainingActionTicks < 0 || $remainingActionTicks > 1_728_000
            || $rewardCursor < 0 || $rewardCursor > count($participantIds)
            || $revision < 0) {
            throw new InvalidArgumentException('Trial-spawner state is outside its supported bounds.');
        }
        if ($phase !== TrialSpawnerPhase::INACTIVE && $participantIds === []) {
            throw new InvalidArgumentException('An active trial-spawner encounter must retain its participants.');
        }
        if (count($activeSpawnedEntityIds) > $configuration->maximumSimultaneousMobs) {
            throw new InvalidArgumentException('Trial-spawner active entity count exceeds its configured limit.');
        }
        if ($phase === TrialSpawnerPhase::INACTIVE
            && ($participantIds !== [] || $activeSpawnedEntityIds !== [] || $spawnedCount !== 0 || $rewardCursor !== 0)) {
            throw new InvalidArgumentException('Inactive trial-spawner state must not retain encounter progress.');
        }
    }

    public static function initial(TrialSpawnerConfiguration $configuration, bool $ominous = false): self
    {
        return new self($configuration, ominous: $ominous);
    }

    /** @param list<string> $participantIds */
    public function begin(array $participantIds): self
    {
        return new self(
            $this->configuration,
            TrialSpawnerPhase::WAITING_FOR_PLAYERS,
            self::normalized($participantIds, 64),
            [],
            0,
            $this->configuration->warmupTicks,
            0,
            $this->ominous,
            $this->revision + 1,
        );
    }

    /** @param list<string> $participantIds */
    public function withParticipants(array $participantIds): self
    {
        $participants = self::normalized([...$this->participantIds, ...$participantIds], 64);

        return $participants === $this->participantIds ? $this : new self(
            $this->configuration,
            $this->phase,
            $participants,
            $this->activeSpawnedEntityIds,
            $this->spawnedCount,
            $this->remainingActionTicks,
            $this->rewardCursor,
            $this->ominous,
            $this->revision + 1,
        );
    }

    public function withPhase(TrialSpawnerPhase $phase, int $remainingActionTicks): self
    {
        return new self(
            $this->configuration,
            $phase,
            $this->participantIds,
            $this->activeSpawnedEntityIds,
            $this->spawnedCount,
            $remainingActionTicks,
            $this->rewardCursor,
            $this->ominous,
            $this->revision + 1,
        );
    }

    /** Advances the persisted relative deadline without depending on a process-local tick origin. */
    public function elapse(int $ticks): self
    {
        if ($ticks < 0 || $ticks > 1_200) {
            throw new InvalidArgumentException('Trial-spawner elapsed ticks are outside the supported bounds.');
        }
        if ($ticks === 0 || $this->remainingActionTicks === 0) {
            return $this;
        }

        return new self(
            $this->configuration,
            $this->phase,
            $this->participantIds,
            $this->activeSpawnedEntityIds,
            $this->spawnedCount,
            max(0, $this->remainingActionTicks - $ticks),
            $this->rewardCursor,
            $this->ominous,
            $this->revision + 1,
        );
    }

    /** @param list<string> $activeEntityIds */
    public function withActiveEntities(array $activeEntityIds): self
    {
        $active = self::normalized($activeEntityIds, 16);

        return $active === $this->activeSpawnedEntityIds ? $this : new self(
            $this->configuration,
            $this->phase,
            $this->participantIds,
            $active,
            $this->spawnedCount,
            $this->remainingActionTicks,
            $this->rewardCursor,
            $this->ominous,
            $this->revision + 1,
        );
    }

    public function recordSpawn(string $entityId, int $remainingSpawnTicks): self
    {
        $active = self::normalized([...$this->activeSpawnedEntityIds, $entityId], 16);

        return new self(
            $this->configuration,
            $this->phase,
            $this->participantIds,
            $active,
            $this->spawnedCount + 1,
            $remainingSpawnTicks,
            $this->rewardCursor,
            $this->ominous,
            $this->revision + 1,
        );
    }

    public function recordReward(int $remainingRewardTicks): self
    {
        if ($this->rewardCursor >= count($this->participantIds)) {
            throw new InvalidArgumentException('Trial-spawner rewards are already exhausted.');
        }

        return new self(
            $this->configuration,
            $this->phase,
            $this->participantIds,
            $this->activeSpawnedEntityIds,
            $this->spawnedCount,
            $remainingRewardTicks,
            $this->rewardCursor + 1,
            $this->ominous,
            $this->revision + 1,
        );
    }

    public function reset(): self
    {
        return new self($this->configuration, ominous: false, revision: $this->revision + 1);
    }

    /** Reconfiguration starts a fresh encounter so old mobs and participant rewards cannot leak across definitions. */
    public function withConfiguration(TrialSpawnerConfiguration $configuration): self
    {
        return $configuration == $this->configuration ? $this : new self(
            $configuration,
            ominous: false,
            revision: $this->revision + 1,
        );
    }

    /** @return array<string, bool|int|string|list<string>> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'entity_identifier' => $this->configuration->entityIdentifier,
            'warmup_ticks' => $this->configuration->warmupTicks,
            'spawn_interval_ticks' => $this->configuration->spawnIntervalTicks,
            'base_total_mobs' => $this->configuration->baseTotalMobs,
            'additional_mobs_per_player' => $this->configuration->additionalMobsPerPlayer,
            'maximum_simultaneous_mobs' => $this->configuration->maximumSimultaneousMobs,
            'cooldown_ticks' => $this->configuration->cooldownTicks,
            'required_player_range' => $this->configuration->requiredPlayerRange,
            'phase' => $this->phase->value,
            'participant_ids' => $this->participantIds,
            'active_spawned_entity_ids' => $this->activeSpawnedEntityIds,
            'spawned_count' => $this->spawnedCount,
            'remaining_action_ticks' => $this->remainingActionTicks,
            'reward_cursor' => $this->rewardCursor,
            'ominous' => $this->ominous,
            'revision' => $this->revision,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (self::integer($data, 'schema') !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported trial-spawner state schema.');
        }

        return new self(
            new TrialSpawnerConfiguration(
                self::string($data, 'entity_identifier'),
                self::integer($data, 'warmup_ticks'),
                self::integer($data, 'spawn_interval_ticks'),
                self::integer($data, 'base_total_mobs'),
                self::integer($data, 'additional_mobs_per_player'),
                self::integer($data, 'maximum_simultaneous_mobs'),
                self::integer($data, 'cooldown_ticks'),
                self::integer($data, 'required_player_range'),
            ),
            TrialSpawnerPhase::from(self::string($data, 'phase')),
            self::stringList($data, 'participant_ids'),
            self::stringList($data, 'active_spawned_entity_ids'),
            self::integer($data, 'spawned_count'),
            self::integer($data, 'remaining_action_ticks'),
            self::integer($data, 'reward_cursor'),
            self::boolean($data, 'ominous'),
            self::integer($data, 'revision'),
        );
    }

    /** @param list<string> $identities */
    private static function identities(array $identities, int $maximum, string $kind): void
    {
        if (count($identities) > $maximum || count(array_unique($identities)) !== count($identities)) {
            throw new InvalidArgumentException("Trial-spawner $kind identities must be a unique bounded list.");
        }
        foreach ($identities as $identity) {
            if ($identity === '' || strlen($identity) > 64 || preg_match('//u', $identity) !== 1) {
                throw new InvalidArgumentException("Trial-spawner $kind identity is malformed.");
            }
        }
    }

    /**
     * @param list<string> $identities
     * @return list<string>
     */
    private static function normalized(array $identities, int $maximum): array
    {
        $identities = array_values(array_unique($identities));
        if (count($identities) > $maximum) {
            throw new InvalidArgumentException('Trial-spawner identity list exceeds its supported bound.');
        }
        self::identities($identities, $maximum, 'state');

        return $identities;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        return is_int($data[$key] ?? null)
            ? $data[$key]
            : throw new InvalidArgumentException("Trial-spawner field '$key' is malformed.");
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null)
            ? $data[$key]
            : throw new InvalidArgumentException("Trial-spawner field '$key' is malformed.");
    }

    /** @param array<string, mixed> $data */
    private static function boolean(array $data, string $key): bool
    {
        return is_bool($data[$key] ?? null)
            ? $data[$key]
            : throw new InvalidArgumentException("Trial-spawner field '$key' is malformed.");
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $values = $data[$key] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException("Trial-spawner field '$key' is malformed.");
        }

        return array_map(
            static fn(mixed $value): string => is_string($value)
                ? $value
                : throw new InvalidArgumentException("Trial-spawner field '$key' is malformed."),
            $values,
        );
    }
}
