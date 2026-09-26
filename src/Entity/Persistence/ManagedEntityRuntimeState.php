<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

/** @internal */
final class ManagedEntityRuntimeState
{
    public function __construct(
        public EntityPersistenceRecord $record,
        public readonly int $runtimeId,
        public int $runtimeRevisionBaseline,
        public int $runtimeAgeBaseline,
        public int $persistedAgeBaseline,
        public bool $durablyStored,
    ) {}
}
