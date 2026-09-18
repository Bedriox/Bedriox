<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

interface WorldEvent
{
    /** @return list<string> */
    public function recipients(): array;
}
