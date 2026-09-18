<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\SimulationClock;
use Bedriox\Server\Simulation\SimulationLimits;
use Bedriox\Server\Simulation\SimulationTick;
use Bedriox\Server\Simulation\SystemSimulationClock;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FixedRateWorldLoopTest extends TestCase
{
    public function testPollRunsAtTwentyTicksPerSecondWithoutEarlyTicks(): void
    {
        $clock = new TestSimulationClock();
        $world = new WorldSimulation();
        $loop = new FixedRateWorldLoop($world, $clock);

        self::assertSame(50_000_000, $loop->nanosecondsUntilNextTick());
        $clock->nanoseconds += 49_999_999;
        self::assertSame([], $loop->poll());
        $clock->nanoseconds++;
        $ticks = $loop->poll();
        self::assertCount(1, $ticks);
        $tick = array_shift($ticks);
        self::assertInstanceOf(SimulationTick::class, $tick);
        self::assertSame(1, $tick->number);
        $clock->nanoseconds += 150_000_000;
        self::assertSame([2, 3, 4], self::tickNumbers($loop->poll()));
    }

    public function testCatchUpWorkIsBoundedPerPollWithoutDroppingTicks(): void
    {
        $clock = new TestSimulationClock();
        $world = new WorldSimulation();
        $loop = new FixedRateWorldLoop($world, $clock, maximumTicksPerPoll: 2);
        $clock->nanoseconds += 250_000_000;

        self::assertSame([1, 2], self::tickNumbers($loop->poll()));
        self::assertSame([3, 4], self::tickNumbers($loop->poll()));
        self::assertSame([5], self::tickNumbers($loop->poll()));
    }

    public function testRateDefaultsToTheWorldConfiguration(): void
    {
        $clock = new TestSimulationClock();
        $loop = new FixedRateWorldLoop(new WorldSimulation(new SimulationLimits(ticksPerSecond: 10)), $clock);
        self::assertSame(100_000_000, $loop->nanosecondsUntilNextTick());
    }

    public function testClockRegressionFailsClosed(): void
    {
        $clock = new TestSimulationClock();
        $loop = new FixedRateWorldLoop(new WorldSimulation(), $clock);
        --$clock->nanoseconds;

        $this->expectException(RuntimeException::class);
        $loop->poll();
    }

    public function testSystemClockIsAvailableForComposition(): void
    {
        self::assertGreaterThan(0, (new SystemSimulationClock())->nowNanoseconds());
    }

    /**
     * @param list<SimulationTick> $ticks
     *
     * @return list<int>
     */
    private static function tickNumbers(array $ticks): array
    {
        $numbers = [];
        foreach ($ticks as $tick) {
            $numbers[] = $tick->number;
        }

        return $numbers;
    }
}

final class TestSimulationClock implements SimulationClock
{
    public int $nanoseconds = 1_000_000;

    public function nowNanoseconds(): int
    {
        return $this->nanoseconds;
    }
}
