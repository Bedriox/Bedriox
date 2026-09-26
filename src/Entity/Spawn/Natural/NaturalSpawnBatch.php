<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use InvalidArgumentException;

final readonly class NaturalSpawnBatch
{
    /** @var list<EntitySpawnRequest> */
    private array $requests;

    /** @param array<int, EntitySpawnRequest> $requests */
    public function __construct(
        array $requests,
        public int $attempts,
        public int $candidateCount,
        public bool $timeBudgetExhausted,
        public bool $attemptBudgetExhausted,
    ) {
        if (!array_is_list($requests) || $attempts < 0 || $candidateCount < 0 || $attempts > $candidateCount) {
            throw new InvalidArgumentException('Natural-spawn batch metrics are invalid.');
        }
        $this->requests = $requests;
    }

    /** @return list<EntitySpawnRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
