<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Observability\Memory;

use Bedriox\Server\Observability\Memory\EmergencyMemoryReserve;
use Bedriox\Server\Observability\Memory\MemorySnapshot;
use Bedriox\Server\Observability\Memory\PhpMemoryUsageProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MemoryPrimitivesTest extends TestCase
{
    public function testSnapshotReportsBoundedAvailabilityAndOvercommit(): void
    {
        $withinLimit = new MemorySnapshot(400, 600, 700, 1_000, 5);
        $overLimit = new MemorySnapshot(1_100, 1_200, 1_200, 1_000, 6);

        self::assertSame(0.6, $withinLimit->utilization());
        self::assertSame(60.0, $withinLimit->utilizationPercent());
        self::assertSame(400, $withinLimit->availableBytes());
        self::assertSame(0, $overLimit->availableBytes());
        self::assertSame(120.0, $overLimit->utilizationPercent());
    }

    public function testSnapshotRejectsImpossibleOrdering(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MemorySnapshot(101, 100, 100, 1_000, 1);
    }

    public function testSmallEmergencyReserveReleasesExactlyOnce(): void
    {
        $reserve = new EmergencyMemoryReserve(64);

        self::assertTrue($reserve->isAvailable());
        self::assertSame(64, $reserve->reservedBytes());
        self::assertSame(64, $reserve->release());
        self::assertFalse($reserve->isAvailable());
        self::assertSame(0, $reserve->reservedBytes());
        self::assertSame(0, $reserve->release());
    }

    public function testNativeProviderProducesInternallyConsistentSnapshot(): void
    {
        $snapshot = (new PhpMemoryUsageProvider(512 * 1024 * 1024))->snapshot();

        self::assertGreaterThan(0, $snapshot->usedBytes);
        self::assertGreaterThanOrEqual($snapshot->usedBytes, $snapshot->allocatedBytes);
        self::assertGreaterThanOrEqual($snapshot->allocatedBytes, $snapshot->peakAllocatedBytes);
        self::assertSame(512 * 1024 * 1024, $snapshot->limitBytes);
        self::assertGreaterThan(0, $snapshot->sampledAtNanoseconds);
    }
}
