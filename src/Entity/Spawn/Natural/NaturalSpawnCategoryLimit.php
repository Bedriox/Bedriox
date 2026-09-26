<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use InvalidArgumentException;

final readonly class NaturalSpawnCategoryLimit
{
    public function __construct(
        public EntityCategory $category,
        public int $worldCap,
        public int $localDensityCap,
    ) {
        if ($worldCap < 0 || $worldCap > 100_000 || $localDensityCap < 0 || $localDensityCap > 10_000) {
            throw new InvalidArgumentException('Natural-spawn category limits are outside their supported bounds.');
        }
    }
}
