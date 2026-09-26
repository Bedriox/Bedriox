<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use InvalidArgumentException;

final readonly class AiWorkBudget
{
    public function __construct(
        public int $maximumEntities = 256,
        public int $maximumNanoseconds = 2_000_000,
    ) {
        if ($maximumEntities < 1 || $maximumEntities > 65_536
            || $maximumNanoseconds < 100_000 || $maximumNanoseconds > 50_000_000) {
            throw new InvalidArgumentException('AI work budget is outside its supported bounds.');
        }
    }
}
