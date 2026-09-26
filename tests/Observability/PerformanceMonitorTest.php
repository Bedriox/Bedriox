<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Server\Entity\EntityRuntimeMetrics;
use Bedriox\Server\Observability\PerformanceMonitor;
use Bedriox\Server\Observability\PerformanceSubsystem;
use PHPUnit\Framework\TestCase;

final class PerformanceMonitorTest extends TestCase
{
    public function testSnapshotUsesBoundedNearestRankTimingHistory(): void
    {
        $monitor = new PerformanceMonitor(maximumSamples: 20, startedAtNanoseconds: 1_000_000_000);
        for ($sample = 1; $sample <= 25; ++$sample) {
            $monitor->recordTick($sample * 1_000_000, 1_000_000_000 + ($sample * 50_000_000));
            $monitor->recordPoll($sample * 500_000);
        }

        $snapshot = $monitor->snapshot(3, 20, 12, 4, 2_250_000_000);

        self::assertSame(1, $snapshot->uptimeSeconds);
        self::assertSame(20.0, $snapshot->currentTps);
        self::assertSame(20.0, $snapshot->averageTps);
        self::assertSame(20.0, $snapshot->minimumTps);
        self::assertSame(15.5, $snapshot->currentMspt);
        self::assertSame(15.5, $snapshot->averageMspt);
        self::assertSame(24.0, $snapshot->p95Mspt);
        self::assertSame(25.0, $snapshot->p99Mspt);
        self::assertSame(31.0, $snapshot->tickUsagePercent);
        self::assertSame(20, $snapshot->tickSamples);
        self::assertSame(7.75, $snapshot->averagePollMilliseconds);
        self::assertSame(12.0, $snapshot->p95PollMilliseconds);
        self::assertSame(3, $snapshot->onlinePlayers);
        self::assertSame(20, $snapshot->maximumPlayers);
        self::assertSame(12, $snapshot->loadedChunks);
        self::assertSame(4, $snapshot->dirtyChunks);
        self::assertGreaterThan(0, $snapshot->memoryBytes);
    }

    public function testEmptyWarmupSnapshotIsSafe(): void
    {
        $entityRuntime = new EntityRuntimeMetrics(4, 2, 1, 2, 1, 0, 1, 1, 50_000, true);
        $snapshot = (new PerformanceMonitor(startedAtNanoseconds: 5_000))->snapshot(
            nowNanoseconds: 5_000,
            entityRuntime: $entityRuntime,
        );

        self::assertSame(0.0, $snapshot->currentTps);
        self::assertSame(0.0, $snapshot->averageTps);
        self::assertSame(0.0, $snapshot->currentMspt);
        self::assertSame(0.0, $snapshot->p99Mspt);
        self::assertSame($entityRuntime, $snapshot->entityRuntime);
    }

    public function testNestedSubsystemSpansRecordExclusiveTimeAndUnclassifiedRemainder(): void
    {
        $monitor = new PerformanceMonitor(startedAtNanoseconds: 0);
        $monitor->beginTick(0);
        $transport = $monitor->startSubsystem(PerformanceSubsystem::TRANSPORT, 10_000_000);
        $world = $monitor->startSubsystem(PerformanceSubsystem::WORLD, 20_000_000);
        $world->end(50_000_000);
        $transport->end(90_000_000);
        $monitor->completeTicks(completedAtNanoseconds: 100_000_000);

        $snapshot = $monitor->snapshot(nowNanoseconds: 100_000_000);
        self::assertSame(50.0, $snapshot->averageSubsystemMilliseconds[PerformanceSubsystem::TRANSPORT]);
        self::assertSame(30.0, $snapshot->averageSubsystemMilliseconds[PerformanceSubsystem::WORLD]);
        self::assertSame(20.0, $snapshot->averageUnclassifiedMilliseconds);
        self::assertSame(100.0, $snapshot->currentMspt);
    }

    public function testImbalancedOrExceptionalSpansCannotCorruptTheNextObservation(): void
    {
        $monitor = new PerformanceMonitor(startedAtNanoseconds: 0);
        $monitor->beginTick(0);
        $stale = $monitor->startSubsystem(PerformanceSubsystem::TRANSPORT, 1_000_000);
        $monitor->endSubsystem(PerformanceSubsystem::WORLD, 1, 2_000_000);

        $monitor->beginTick(10_000_000);
        $world = $monitor->startSubsystem(PerformanceSubsystem::WORLD, 11_000_000);
        $stale->end(12_000_000);
        $world->end(15_000_000);
        $monitor->completeTicks(completedAtNanoseconds: 20_000_000);

        $snapshot = $monitor->snapshot(nowNanoseconds: 20_000_000);
        self::assertSame(1, $snapshot->timerImbalances);
        self::assertSame(4.0, $snapshot->averageSubsystemMilliseconds[PerformanceSubsystem::WORLD]);
        self::assertSame(10.0, $snapshot->currentMspt);
    }

    public function testSubsystemSpanClosesThroughAnExceptionalExit(): void
    {
        $monitor = new PerformanceMonitor(startedAtNanoseconds: 0);
        $monitor->beginTick(0);
        $span = $monitor->startSubsystem(PerformanceSubsystem::PLUGINS, 1_000_000);
        try {
            throw new \RuntimeException('fixture');
        } catch (\RuntimeException) {
            $span->end(6_000_000);
        }
        $monitor->completeTicks(completedAtNanoseconds: 10_000_000);

        $snapshot = $monitor->snapshot(nowNanoseconds: 10_000_000);
        self::assertSame(5.0, $snapshot->averageSubsystemMilliseconds[PerformanceSubsystem::PLUGINS]);
        self::assertSame(5.0, $snapshot->averageUnclassifiedMilliseconds);
        self::assertSame(0, $snapshot->timerImbalances);
    }

    public function testCompletedMetricsAndNetworkRatesChangeOnlyAtTickBoundary(): void
    {
        $monitor = new PerformanceMonitor(startedAtNanoseconds: 0);
        $monitor->recordNetworkReceived(1_000, 1_000_000_000);
        $monitor->recordNetworkSent(2_000, 1_000_000_000);
        $before = $monitor->snapshot(nowNanoseconds: 1_000_000_000);
        self::assertNull($before->networkReceiveBytesPerSecond);
        self::assertNull($before->networkSendBytesPerSecond);

        $monitor->recordTick(5_000_000, 1_000_000_000);
        $completed = $monitor->snapshot(nowNanoseconds: 1_500_000_000);
        self::assertSame(100.0, $completed->networkReceiveBytesPerSecond);
        self::assertSame(200.0, $completed->networkSendBytesPerSecond);

        $monitor->beginTick(2_000_000_000);
        self::assertSame($completed->currentMspt, $monitor->snapshot(nowNanoseconds: 2_500_000_000)->currentMspt);
        $monitor->cancelTick();
    }
}
