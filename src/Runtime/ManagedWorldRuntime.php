<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\World as PublicWorld;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\SimulationTick;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use LogicException;

/** Owns every heavyweight service for exactly one loaded world generation. */
final class ManagedWorldRuntime
{
    private bool $closed = false;

    public function __construct(
        public readonly PublicWorld $handle,
        public readonly OpenedWorld $opened,
        public readonly WorldSimulation $simulation,
        public readonly FixedRateWorldLoop $loop,
        public readonly ?PreparedChunkCache $preparedChunks = null,
    ) {}

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

    /** Persists all authoritative entity, chunk, and world metadata state. */
    public function save(): void
    {
        $this->assertOpen();
        $entityResult = $this->simulation->flushEntityPersistence();
        if ($entityResult->failedChunksCount() !== 0) {
            throw new \RuntimeException('One or more world entities could not be persisted.');
        }
        $this->opened->world->flush();
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
            $this->opened->world->close($save);
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
            throw new LogicException('World runtime is closed.');
        }
    }
}
