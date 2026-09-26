<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityType;
use InvalidArgumentException;

final readonly class NaturalSpawnEntry
{
    public function __construct(
        public EntityType $type,
        public EntityCategory $category,
        public NaturalSpawnRule $rule,
        public int $weight = 1,
    ) {
        if ($weight < 1 || $weight > 1_000) {
            throw new InvalidArgumentException('Natural-spawn entry weight is outside its supported bounds.');
        }
    }
}
