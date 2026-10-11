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
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\SimulationTick;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use LogicException;

/** Owns the heavyweight services for exactly one loaded dimension runtime. */
final class ManagedWorldRuntime
{
    private bool $closed = false;

    public function __construct(
        public readonly PublicWorld $handle,
        public readonly OpenedWorld $opened,
        public readonly WorldSimulation $simulation,
        public readonly FixedRateWorldLoop $loop,
        public readonly ?PreparedChunkCache $preparedChunks = null,
    ) {
        if ($handle->getDimensionType() !== $opened->world->dimension()) {
            throw new LogicException('A public world handle must identify the runtime dimension it owns.');
        }
    }

    public function playerCount(): int
    {
        return count($this->simulation->snapshot()->players);
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

    /** Persists this dimension's authoritative entity, chunk, and optional metadata state. */
    public function save(bool $saveMetadata = false): void
    {
        $this->assertOpen();
        $entityResult = $this->simulation->flushEntityPersistence();
        if ($entityResult->failedChunksCount() !== 0) {
            throw new \RuntimeException('One or more world entities could not be persisted.');
        }
        $this->opened->world->flush($saveMetadata);
    }

    /** @internal Storage ownership decides which dimension closes the shared provider. */
    public function close(bool $save, bool $closeProvider = false, bool $saveMetadata = false): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->playerCount() !== 0) {
            throw new LogicException('An occupied world dimension runtime cannot be closed.');
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
            $this->opened->world->close($save, $closeProvider, $saveMetadata);
        } catch (\Throwable $error) {
            $failure ??= $error;
        } finally {
            $this->closed = true;
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
            throw new LogicException('World dimension runtime is closed.');
        }
    }
}
