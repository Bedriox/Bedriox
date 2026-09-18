<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

interface SimulationClock
{
    public function nowNanoseconds(): int;
}
