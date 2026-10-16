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

use Bedriox\Api\World\Spawner\SpawnerConfiguration;
use InvalidArgumentException;

/** Immutable restart-safe ordinary-spawner state. */
final readonly class MobSpawnerState
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public SpawnerConfiguration $configuration,
        public int $remainingDelayTicks = 200,
        public bool $active = false,
        public int $revision = 0,
    ) {
        if ($remainingDelayTicks < 0 || $remainingDelayTicks > $configuration->maximumDelayTicks || $revision < 0) {
            throw new InvalidArgumentException('Mob-spawner state is outside its supported bounds.');
        }
    }

    public static function initial(SpawnerConfiguration $configuration): self
    {
        return new self($configuration, $configuration->minimumDelayTicks);
    }

    public function withActivity(bool $active): self
    {
        return $active === $this->active
            ? $this
            : new self($this->configuration, $this->remainingDelayTicks, $active, $this->revision + 1);
    }

    public function withDelay(int $remainingDelayTicks): self
    {
        return $remainingDelayTicks === $this->remainingDelayTicks
            ? $this
            : new self($this->configuration, $remainingDelayTicks, $this->active, $this->revision + 1);
    }

    public function withConfiguration(SpawnerConfiguration $configuration): self
    {
        return $configuration == $this->configuration ? $this : new self(
            $configuration,
            min($this->remainingDelayTicks, $configuration->maximumDelayTicks),
            $this->active,
            $this->revision + 1,
        );
    }

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'entity_identifier' => $this->configuration->entityIdentifier,
            'minimum_delay_ticks' => $this->configuration->minimumDelayTicks,
            'maximum_delay_ticks' => $this->configuration->maximumDelayTicks,
            'spawn_count' => $this->configuration->spawnCount,
            'maximum_nearby_entities' => $this->configuration->maximumNearbyEntities,
            'required_player_range' => $this->configuration->requiredPlayerRange,
            'spawn_range' => $this->configuration->spawnRange,
            'remaining_delay_ticks' => $this->remainingDelayTicks,
            'active' => $this->active,
            'revision' => $this->revision,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (self::integer($data, 'schema') !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported mob-spawner state schema.');
        }

        return new self(
            new SpawnerConfiguration(
                self::string($data, 'entity_identifier'),
                self::integer($data, 'minimum_delay_ticks'),
                self::integer($data, 'maximum_delay_ticks'),
                self::integer($data, 'spawn_count'),
                self::integer($data, 'maximum_nearby_entities'),
                self::integer($data, 'required_player_range'),
                self::integer($data, 'spawn_range'),
            ),
            self::integer($data, 'remaining_delay_ticks'),
            self::boolean($data, 'active'),
            self::integer($data, 'revision'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        return is_int($data[$key] ?? null)
            ? $data[$key]
            : throw new InvalidArgumentException("Mob-spawner field '$key' is malformed.");
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null)
            ? $data[$key]
            : throw new InvalidArgumentException("Mob-spawner field '$key' is malformed.");
    }

    /** @param array<string, mixed> $data */
    private static function boolean(array $data, string $key): bool
    {
        return is_bool($data[$key] ?? null)
            ? $data[$key]
            : throw new InvalidArgumentException("Mob-spawner field '$key' is malformed.");
    }
}
