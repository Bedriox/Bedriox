<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\World\ChunkLoadCompletion;
use Bedriox\Server\World\ChunkPosition;

/** Nonblocking streaming and autosave boundary for a provider owned outside the simulation process. */
interface AsynchronousWorldProvider extends WritableWorldProvider
{
    public function requestChunkLoad(ChunkPosition $position): bool;

    /** @return list<ChunkLoadCompletion> */
    public function pollChunkLoads(int $maximumCompletions = 256): array;

    public function enqueueChunkSave(ChunkSaveData $chunkData): PersistenceEnqueueResult;

    /** @return list<PersistenceWriteCompletion> */
    public function pollChunkSaves(int $maximumCompletions = 256): array;

    /** @return list<PersistenceWriteCompletion> */
    public function drainChunkSaves(int $timeoutMilliseconds): array;
}
