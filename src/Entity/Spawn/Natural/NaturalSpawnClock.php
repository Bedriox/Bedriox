<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

interface NaturalSpawnClock
{
    public function nowNanoseconds(): int;
}
