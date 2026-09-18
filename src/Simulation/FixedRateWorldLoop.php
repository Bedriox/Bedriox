<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use RuntimeException;

final class FixedRateWorldLoop
{
    private readonly int $intervalNanoseconds;
    private int $nextTickNanoseconds;
    private int $lastObservedNanoseconds;

    public function __construct(
        private readonly WorldSimulation $world,
        private readonly SimulationClock $clock,
        ?int $ticksPerSecond = null,
        private readonly int $maximumTicksPerPoll = 5,
    ) {
        $ticksPerSecond ??= $world->ticksPerSecond();
        if ($ticksPerSecond < 1 || $ticksPerSecond > 1000 || $maximumTicksPerPoll < 1) {
            throw new RuntimeException('Fixed-rate loop limits are invalid.');
        }
        $this->intervalNanoseconds = intdiv(1_000_000_000, $ticksPerSecond);
        $this->lastObservedNanoseconds = $clock->nowNanoseconds();
        $this->nextTickNanoseconds = $this->lastObservedNanoseconds + $this->intervalNanoseconds;
    }

    /** @return list<SimulationTick> */
    public function poll(): array
    {
        $now = $this->clock->nowNanoseconds();
        if ($now < $this->lastObservedNanoseconds) {
            throw new RuntimeException('Monotonic simulation clock moved backwards.');
        }
        $this->lastObservedNanoseconds = $now;
        $ticks = [];
        while ($now >= $this->nextTickNanoseconds && count($ticks) < $this->maximumTicksPerPoll) {
            $ticks[] = $this->world->tick();
            $this->nextTickNanoseconds += $this->intervalNanoseconds;
        }

        return $ticks;
    }

    public function nanosecondsUntilNextTick(): int
    {
        $now = $this->clock->nowNanoseconds();
        if ($now < $this->lastObservedNanoseconds) {
            throw new RuntimeException('Monotonic simulation clock moved backwards.');
        }
        $this->lastObservedNanoseconds = $now;

        return max(0, $this->nextTickNanoseconds - $now);
    }
}
