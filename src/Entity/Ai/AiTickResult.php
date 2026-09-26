<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

final readonly class AiTickResult
{
    public function __construct(
        public int $sensorsRun,
        public int $goalsEvaluated,
        public int $goalsTicked,
    ) {}
}
