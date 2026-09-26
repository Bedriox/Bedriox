<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractMobEntity;

/** Read-only AI query boundary; implementations must use bounded spatial lookups. */
interface AiWorldView
{
    /** @return list<AbstractEntity> */
    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array;

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float;
}
