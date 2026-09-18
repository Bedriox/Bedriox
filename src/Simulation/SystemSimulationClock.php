<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

final class SystemSimulationClock implements SimulationClock
{
    public function nowNanoseconds(): int
    {
        return hrtime(true);
    }
}
