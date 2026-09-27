<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

/** @internal Main-thread bookkeeping for one in-flight durable ownership move. */
final readonly class PendingEntityOwnershipTransfer
{
    /**
     * @param array<string, array{revision: int, age: int}> $runtimeBaselines
     */
    public function __construct(
        public EntityOwnershipTransfer $transfer,
        public string $sourceKey,
        public string $destinationKey,
        public int $sourceMutationRevision,
        public int $destinationMutationRevision,
        public int $runtimeRevisionBaseline,
        public int $runtimeAgeBaseline,
        public array $runtimeBaselines,
    ) {}
}
