<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

interface NaturalDespawnRandom
{
    public function oneIn(int $chance): bool;
}
