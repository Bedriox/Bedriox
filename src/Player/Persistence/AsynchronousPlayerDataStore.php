<?php

declare(strict_types=1);

namespace Bedriox\Server\Player\Persistence;

use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Player\PlayerBootstrap;

/** Nonblocking routine-save boundary for a player store owned outside the simulation process. */
interface AsynchronousPlayerDataStore extends PlayerDataStore
{
    public function enqueueSave(PlayerBootstrap $player, int $revision): PersistenceEnqueueResult;

    /** @return list<PersistenceWriteCompletion> */
    public function pollSaves(int $maximumCompletions = 256): array;

    /** @return list<PersistenceWriteCompletion> */
    public function drainSaves(int $timeoutMilliseconds): array;

    public function close(): void;
}
