<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Tests\Observability\Memory;

use Bedriox\Server\Observability\Memory\GarbageCollector;
use Bedriox\Server\Observability\Memory\GarbageCollectorBackend;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GarbageCollectorTest extends TestCase
{
    public function testCollectionIsSkippedBelowTheAdaptiveThreshold(): void
    {
        $backend = new FakeGarbageCollectorBackend(roots: [99]);
        $collector = new GarbageCollector(
            $backend,
            minimumThreshold: 100,
            maximumThreshold: 300,
            thresholdStep: 50,
            minimumProductiveCycles: 10,
        );

        $report = $collector->maybeCollect();

        self::assertFalse($report->collected);
        self::assertSame(99, $report->rootsBefore);
        self::assertSame(100, $report->thresholdAfter);
        self::assertSame(0, $collector->runs());
        self::assertSame(0, $backend->collections);
        self::assertSame(0, $backend->cacheReleases);
    }

    public function testThresholdGrowsAfterAnUnproductiveCollectionAndShrinksAfterAProductiveOne(): void
    {
        $backend = new FakeGarbageCollectorBackend(
            roots: [100, 5, 150, 0],
            cycles: [5, 50],
            times: [10, 30, 40, 70],
        );
        $collector = new GarbageCollector(
            $backend,
            minimumThreshold: 100,
            maximumThreshold: 300,
            thresholdStep: 50,
            minimumProductiveCycles: 10,
        );

        $unproductive = $collector->maybeCollect();
        $productive = $collector->maybeCollect();

        self::assertTrue($unproductive->collected);
        self::assertSame(20, $unproductive->durationNanoseconds);
        self::assertSame(150, $unproductive->thresholdAfter);
        self::assertSame(100, $productive->thresholdAfter);
        self::assertSame(2, $collector->runs());
        self::assertSame(50, $collector->totalCollectionNanoseconds());
        self::assertSame(0, $backend->cacheReleases);
    }

    public function testForcedCollectionAlsoReleasesAllocatorCaches(): void
    {
        $backend = new FakeGarbageCollectorBackend(
            roots: [2, 0],
            cycles: [1],
            cacheBytes: [4_096],
            times: [100, 125],
        );
        $collector = new GarbageCollector($backend);

        $report = $collector->collectNow();

        self::assertTrue($report->collected);
        self::assertTrue($report->forced);
        self::assertTrue($report->allocatorCachesReleased);
        self::assertSame(4_096, $report->allocatorBytesReleased);
        self::assertSame(25, $report->durationNanoseconds);
        self::assertSame(1, $backend->collections);
        self::assertSame(1, $backend->cacheReleases);
    }

    public function testInvalidBoundsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GarbageCollector(new FakeGarbageCollectorBackend(), minimumThreshold: 0);
    }
}

final class FakeGarbageCollectorBackend implements GarbageCollectorBackend
{
    public int $collections = 0;
    public int $cacheReleases = 0;

    /**
     * @param list<int> $roots
     * @param list<int> $cycles
     * @param list<int> $cacheBytes
     * @param list<int> $times
     */
    public function __construct(
        private array $roots = [],
        private array $cycles = [],
        private array $cacheBytes = [],
        private array $times = [],
    ) {}

    public function rootCount(): int
    {
        return array_shift($this->roots) ?? 0;
    }

    public function collectCycles(): int
    {
        ++$this->collections;

        return array_shift($this->cycles) ?? 0;
    }

    public function releaseAllocatorCaches(): int
    {
        ++$this->cacheReleases;

        return array_shift($this->cacheBytes) ?? 0;
    }

    public function monotonicNanoseconds(): int
    {
        return array_shift($this->times) ?? 0;
    }
}
