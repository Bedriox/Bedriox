<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use InvalidArgumentException;

/** Bounds expensive entity physics while safety-critical motion remains continuous. */
final readonly class EntityWorkBudget
{
    public function __construct(
        public int $maximumPhysicsEntities = 512,
        public int $maximumNanoseconds = 4_000_000,
    ) {
        if ($maximumPhysicsEntities < 1 || $maximumPhysicsEntities > 65_536
            || $maximumNanoseconds < 100_000 || $maximumNanoseconds > 50_000_000) {
            throw new InvalidArgumentException('Entity work budget is outside its supported bounds.');
        }
    }
}
