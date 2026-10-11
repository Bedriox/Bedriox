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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\DimensionType;
use Bedriox\Server\Persistence\PersistenceSubmission;
use LogicException;

/** @internal Owns every dimension runtime backed by one named Bedrock world storage provider. */
final class WorldStorageRuntime
{
    /** @var array<string, ManagedWorldRuntime> */
    private array $dimensions = [];
    private bool $closed = false;

    /** @param iterable<ManagedWorldRuntime> $dimensions */
    public function __construct(iterable $dimensions)
    {
        $worldId = null;
        $loadGeneration = null;
        foreach ($dimensions as $runtime) {
            $handle = $runtime->handle;
            $worldId ??= $handle->id();
            $loadGeneration ??= $handle->loadGeneration();
            if ($handle->id() !== $worldId || $handle->loadGeneration() !== $loadGeneration) {
                throw new LogicException('Every dimension runtime must share one named world load generation.');
            }
            $key = $handle->getDimensionType()->value;
            if (isset($this->dimensions[$key])) {
                throw new LogicException('A named world cannot contain a duplicate dimension runtime.');
            }
            $this->dimensions[$key] = $runtime;
        }
        if (!isset($this->dimensions[DimensionType::OVERWORLD->value])) {
            throw new LogicException('A named world storage runtime requires an Overworld root.');
        }
    }

    public function root(): ManagedWorldRuntime
    {
        return $this->dimensions[DimensionType::OVERWORLD->value];
    }

    public function dimension(DimensionType $dimension): ?ManagedWorldRuntime
    {
        return $this->dimensions[$dimension->value] ?? null;
    }

    /** @return list<ManagedWorldRuntime> */
    public function dimensions(): array
    {
        return array_values($this->dimensions);
    }

    public function playerCount(): int
    {
        return array_sum(array_map(
            static fn(ManagedWorldRuntime $runtime): int => $runtime->playerCount(),
            $this->dimensions,
        ));
    }

    public function save(): void
    {
        $this->assertOpen();
        foreach ($this->dimensions as $runtime) {
            $runtime->save(false);
        }
        $this->root()->opened->world->saveWorldData($this->dimensionMetadata());
    }

    public function scheduleWorldDataSave(): PersistenceSubmission
    {
        $this->assertOpen();

        return $this->root()->opened->world->scheduleWorldDataSave($this->dimensionMetadata());
    }

    public function close(bool $save): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->playerCount() !== 0) {
            throw new LogicException('An occupied world storage runtime cannot be closed.');
        }

        $failure = null;
        if ($save) {
            try {
                $this->save();
            } catch (\Throwable $error) {
                $failure = $error;
            }
        }
        foreach (array_reverse($this->dimensions()) as $runtime) {
            try {
                $isRoot = $runtime === $this->root();
                $runtime->close(false, closeProvider: $isRoot);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        $this->closed = true;
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('World storage runtime is closed.');
        }
    }

    /** @return array<string, \Bedriox\Server\World\Provider\DimensionWorldData> */
    private function dimensionMetadata(): array
    {
        $metadata = [];
        foreach ($this->dimensions as $dimension => $runtime) {
            $metadata[$dimension] = $runtime->opened->world->currentDimensionData();
        }

        return $metadata;
    }
}
