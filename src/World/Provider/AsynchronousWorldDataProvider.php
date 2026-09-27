<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;

/** Nonblocking persistence boundary for mutable world metadata such as time and spawn. */
interface AsynchronousWorldDataProvider extends WritableWorldProvider
{
    public function enqueueWorldDataSave(WorldData $worldData): PersistenceEnqueueResult;

    /** @return list<PersistenceWriteCompletion> */
    public function pollWorldDataSaves(int $maximumCompletions = 16): array;
}
