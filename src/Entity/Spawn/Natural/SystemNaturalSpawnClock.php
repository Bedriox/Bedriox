<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

final class SystemNaturalSpawnClock implements NaturalSpawnClock
{
    public function nowNanoseconds(): int
    {
        return hrtime(true);
    }
}
