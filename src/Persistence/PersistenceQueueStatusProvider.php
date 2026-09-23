<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence;

interface PersistenceQueueStatusProvider
{
    public function persistenceQueueSnapshot(): PersistenceQueueSnapshot;
}
