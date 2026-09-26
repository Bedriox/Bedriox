<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

final readonly class EntityPhysicsResult
{
    public function __construct(
        public bool $moved,
        public bool $motionChanged,
        public bool $landed,
    ) {}
}
