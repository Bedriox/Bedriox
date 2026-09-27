<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

/** @internal Main-thread baseline for one in-flight immutable entity-chunk snapshot. */
final readonly class PendingEntityChunkSave
{
    /** @param array<string, array{revision: int, age: int}> $runtimeBaselines */
    public function __construct(
        public EntityChunkSnapshot $snapshot,
        public int $mutationRevision,
        public array $runtimeBaselines,
    ) {}
}
