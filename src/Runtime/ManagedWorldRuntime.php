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

use Bedriox\Api\World\World as PublicWorld;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\SimulationTick;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use LogicException;

/** Owns every heavyweight service for exactly one loaded world generation. */
final class ManagedWorldRuntime
{
    private bool $closed = false;

    /** @var array<string, self> */
    private array $dimensionRuntimes;

    /** @param iterable<self> $additionalDimensions */
    public function __construct(
        public readonly PublicWorld $handle,
        public readonly OpenedWorld $opened,
        public readonly WorldSimulation $simulation,
        public readonly FixedRateWorldLoop $loop,
        public readonly ?PreparedChunkCache $preparedChunks = null,
        iterable $additionalDimensions = [],
    ) {
        $rootDimension = $opened->world->dimension();
        if (!$handle->hasDimension($rootDimension)) {
            throw new LogicException('A runtime dimension must be declared by its named world handle.');
        }
        $this->dimensionRuntimes = [$rootDimension->value => $this];
        foreach ($additionalDimensions as $runtime) {
            if ($rootDimension !== WorldDimension::OVERWORLD) {
                throw new LogicException('A named world family must be rooted in its Overworld dimension.');
            }
            if ($runtime->dimensionRuntimes !== [$runtime->opened->world->dimension()->value => $runtime]) {
                throw new LogicException('A nested named world family cannot be attached as a dimension.');
            }
            if (!$runtime->handle->isSameLoad($handle)) {
                throw new LogicException('Every dimension runtime must share the named world handle.');
            }
            $dimension = $runtime->opened->world->dimension();
            if (!$handle->hasDimension($dimension)) {
                throw new LogicException('A runtime dimension must be declared by its named world handle.');
            }
            if ($dimension === WorldDimension::OVERWORLD || isset($this->dimensionRuntimes[$dimension->value])) {
                throw new LogicException('A named world cannot contain a duplicate dimension runtime.');
            }
            $this->dimensionRuntimes[$dimension->value] = $runtime;
        }
    }

    public function dimension(WorldDimension $dimension = WorldDimension::OVERWORLD): ?self
    {
        return $this->dimensionRuntimes[$dimension->value] ?? null;
    }

    /** @return list<self> */
    public function dimensions(): array
    {
        return array_values($this->dimensionRuntimes);
    }

    public function playerCount(): int
    {
        return array_sum(array_map(
            static fn(self $runtime): int => count($runtime->simulation->snapshot()->players),
            $this->dimensionRuntimes,
        ));
    }

    /** @return list<SimulationTick> */
    public function poll(): array
    {
        $this->assertOpen();

        return $this->loop->poll();
    }

    public function nanosecondsUntilNextTick(): int
    {
        $this->assertOpen();

        return $this->loop->nanosecondsUntilNextTick();
    }

    /** Persists all authoritative entity, chunk, and world metadata state. */
    public function save(): void
    {
        $this->assertOpen();
        foreach ($this->dimensionRuntimes as $runtime) {
            $runtime->saveDimension();
        }
    }

    /** Closes this generation after the manager has established that no players remain. */
    public function close(bool $save): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->playerCount() !== 0) {
            throw new LogicException('An occupied world runtime cannot be closed.');
        }

        $failure = null;
        $dimensions = array_reverse($this->dimensions());
        foreach ($dimensions as $runtime) {
            try {
                $runtime->closeDimension($save, $runtime === $this);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
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
            throw new LogicException('World runtime is closed.');
        }
    }

    private function saveDimension(): void
    {
        if ($this->closed) {
            throw new LogicException('World dimension runtime is closed.');
        }
        $entityResult = $this->simulation->flushEntityPersistence();
        if ($entityResult->failedChunksCount() !== 0) {
            throw new \RuntimeException('One or more world entities could not be persisted.');
        }
        $this->opened->world->flush($this->opened->world->dimension() === WorldDimension::OVERWORLD);
    }

    private function closeDimension(bool $save, bool $closeProvider): void
    {
        if ($this->closed) {
            return;
        }
        $failure = null;
        $this->simulation->beginShutdown();
        try {
            if ($save) {
                $entityResult = $this->simulation->flushEntityPersistence();
                if ($entityResult->failedChunksCount() !== 0) {
                    throw new \RuntimeException('One or more world entities could not be persisted.');
                }
            }
        } catch (\Throwable $error) {
            $failure = $error;
        }
        try {
            $this->preparedChunks?->close();
            $this->opened->world->close(
                $save,
                $closeProvider,
                $this->opened->world->dimension() === WorldDimension::OVERWORLD,
            );
        } catch (\Throwable $error) {
            $failure ??= $error;
        } finally {
            $this->closed = true;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
