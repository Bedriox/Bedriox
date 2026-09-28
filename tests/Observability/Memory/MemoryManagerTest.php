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

use Bedriox\Server\Observability\Memory\MemoryManager;
use Bedriox\Server\Observability\Memory\MemoryPressure;
use Bedriox\Server\Observability\Memory\MemoryReserve;
use Bedriox\Server\Observability\Memory\MemorySnapshot;
use Bedriox\Server\Observability\Memory\MemoryUsageProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MemoryManagerTest extends TestCase
{
    public function testPressureActionsEscalateAtTheConfiguredBoundaries(): void
    {
        $provider = new SequenceMemoryUsageProvider([
            self::snapshot(699),
            self::snapshot(700),
            self::snapshot(850),
            self::snapshot(920),
        ]);
        $reserve = new FakeMemoryReserve(2_048);
        $manager = new MemoryManager($provider, $reserve);

        $normal = $manager->evaluate();
        self::assertSame(MemoryPressure::NORMAL, $normal->pressure);
        self::assertFalse($normal->collectCycles);
        self::assertSame(0, $normal->maximumChunkUnloads);

        $elevated = $manager->evaluate();
        self::assertSame(MemoryPressure::ELEVATED, $elevated->pressure);
        self::assertTrue($elevated->collectCycles);
        self::assertTrue($elevated->accelerateChunkUnloading);
        self::assertTrue($elevated->trimDisposableCaches);
        self::assertFalse($elevated->suspendPrefetch);
        self::assertSame(32, $elevated->maximumChunkUnloads);

        $high = $manager->evaluate();
        self::assertSame(MemoryPressure::HIGH, $high->pressure);
        self::assertTrue($high->releaseAllocatorCaches);
        self::assertTrue($high->suspendPrefetch);
        self::assertTrue($high->prioritizePersistence);
        self::assertTrue($high->pauseOptionalGeneration);
        self::assertSame(64, $high->maximumChunkUnloads);

        $critical = $manager->evaluate();
        self::assertSame(MemoryPressure::CRITICAL, $critical->pressure);
        self::assertSame(96, $critical->maximumChunkUnloads);
        self::assertSame(2_048, $critical->emergencyReserveBytesReleased);
        self::assertSame(1, $reserve->releases);
    }

    public function testHysteresisPreventsPressureFromOscillatingNearBoundaries(): void
    {
        $manager = new MemoryManager(
            new SequenceMemoryUsageProvider([
                self::snapshot(920),
                self::snapshot(900),
                self::snapshot(889),
                self::snapshot(830),
                self::snapshot(819),
                self::snapshot(680),
                self::snapshot(669),
            ]),
            new FakeMemoryReserve(1_024),
        );

        self::assertSame(MemoryPressure::CRITICAL, $manager->evaluate()->pressure);
        self::assertSame(MemoryPressure::CRITICAL, $manager->evaluate()->pressure);
        self::assertSame(MemoryPressure::HIGH, $manager->evaluate()->pressure);
        self::assertSame(MemoryPressure::HIGH, $manager->evaluate()->pressure);
        self::assertSame(MemoryPressure::ELEVATED, $manager->evaluate()->pressure);
        self::assertSame(MemoryPressure::ELEVATED, $manager->evaluate()->pressure);
        self::assertSame(MemoryPressure::NORMAL, $manager->evaluate()->pressure);
    }

    public function testEmergencyReserveIsReleasedOnlyOnceDuringSustainedCriticalPressure(): void
    {
        $reserve = new FakeMemoryReserve(4_096);
        $manager = new MemoryManager(
            new SequenceMemoryUsageProvider([self::snapshot(950), self::snapshot(960)]),
            $reserve,
        );

        self::assertSame(4_096, $manager->evaluate()->emergencyReserveBytesReleased);
        self::assertSame(0, $manager->evaluate()->emergencyReserveBytesReleased);
        self::assertSame(1, $reserve->releases);
    }

    public function testUnlimitedProcessIsNotClassifiedAsUnderPressure(): void
    {
        $snapshot = new MemorySnapshot(500, 600, 700, 0, 1);
        $manager = new MemoryManager(new SequenceMemoryUsageProvider([$snapshot]), new FakeMemoryReserve(64));

        self::assertSame(MemoryPressure::NORMAL, $manager->evaluate()->pressure);
        self::assertSame(0.0, $snapshot->utilizationPercent());
        self::assertNull($snapshot->availableBytes());
    }

    public function testInvalidPressureBoundariesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MemoryManager(
            new SequenceMemoryUsageProvider([self::snapshot(1)]),
            new FakeMemoryReserve(64),
            elevatedPercent: 85.0,
            highPercent: 70.0,
        );
    }

    private static function snapshot(int $allocatedBytes): MemorySnapshot
    {
        return new MemorySnapshot(
            usedBytes: $allocatedBytes,
            allocatedBytes: $allocatedBytes,
            peakAllocatedBytes: $allocatedBytes,
            limitBytes: 1_000,
            sampledAtNanoseconds: 1,
        );
    }
}

final class SequenceMemoryUsageProvider implements MemoryUsageProvider
{
    /** @var list<MemorySnapshot> */
    private array $snapshots;

    /** @param non-empty-list<MemorySnapshot> $snapshots */
    public function __construct(array $snapshots)
    {
        $this->snapshots = $snapshots;
    }

    public function snapshot(): MemorySnapshot
    {
        $snapshot = array_shift($this->snapshots);
        if ($snapshot === null) {
            throw new \RuntimeException('No memory snapshot remains.');
        }

        return $snapshot;
    }
}

final class FakeMemoryReserve implements MemoryReserve
{
    public int $releases = 0;
    private bool $available = true;

    public function __construct(private readonly int $bytes) {}

    public function reservedBytes(): int
    {
        return $this->available ? $this->bytes : 0;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function release(): int
    {
        if (!$this->available) {
            return 0;
        }
        $this->available = false;
        ++$this->releases;

        return $this->bytes;
    }
}
