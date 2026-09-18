<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Server\Simulation\Event\WorldEvent;

final readonly class SimulationTick
{
    /** @param list<WorldEvent> $events */
    public function __construct(
        public int $number,
        public int $processedCommands,
        public array $events,
    ) {}
}
