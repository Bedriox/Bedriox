<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

final readonly class AiSchedulerMetrics
{
    public function __construct(
        public int $considered,
        public int $ticked,
        public int $active,
        public int $reduced,
        public int $sleeping,
        public int $sensorsRun,
        public int $goalsEvaluated,
        public int $goalsTicked,
        public int $elapsedNanoseconds,
        public bool $budgetExhausted,
    ) {}
}
