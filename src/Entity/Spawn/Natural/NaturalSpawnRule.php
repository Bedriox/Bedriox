<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

interface NaturalSpawnRule
{
    public function allows(NaturalSpawnContext $context): bool;
}
