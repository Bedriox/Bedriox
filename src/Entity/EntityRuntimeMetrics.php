<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

final readonly class EntityRuntimeMetrics
{
    public function __construct(
        public int $entities,
        public int $physicsEligible,
        public int $physicsTicked,
        public int $cadenceSkipped,
        public int $budgetDeferred,
        public int $continuousBeyondBudget,
        public int $moved,
        public int $motionChanged,
        public int $elapsedNanoseconds,
        public bool $budgetExhausted,
    ) {}
}
