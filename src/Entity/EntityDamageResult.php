<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

final readonly class EntityDamageResult
{
    public function __construct(
        public AbstractLivingEntity $entity,
        public float $appliedDamage,
        public bool $died,
    ) {}
}
