<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability\Memory;

use InvalidArgumentException;

/** Runs cyclic collection at predictable boundaries using an adaptive root threshold. */
final class GarbageCollector
{
    public const DEFAULT_THRESHOLD = 10_001;

    private int $threshold;
    private int $runs = 0;
    private int $totalCollectionNanoseconds = 0;

    public function __construct(
        private readonly GarbageCollectorBackend $backend,
        private readonly int $minimumThreshold = self::DEFAULT_THRESHOLD,
        private readonly int $maximumThreshold = 1_000_000_000,
        private readonly int $thresholdStep = 10_000,
        private readonly int $minimumProductiveCycles = 100,
    ) {
        if ($minimumThreshold < 1 || $maximumThreshold < $minimumThreshold || $thresholdStep < 1
            || $minimumProductiveCycles < 1) {
            throw new InvalidArgumentException('Garbage collector limits are invalid.');
        }
        $this->threshold = $minimumThreshold;
    }

    public function threshold(): int
    {
        return $this->threshold;
    }

    public function runs(): int
    {
        return $this->runs;
    }

    public function totalCollectionNanoseconds(): int
    {
        return $this->totalCollectionNanoseconds;
    }

    public function maybeCollect(): GarbageCollectionReport
    {
        return $this->collect(force: false, releaseAllocatorCaches: false);
    }

    public function collectNow(bool $releaseAllocatorCaches = true): GarbageCollectionReport
    {
        return $this->collect(force: true, releaseAllocatorCaches: $releaseAllocatorCaches);
    }

    private function collect(bool $force, bool $releaseAllocatorCaches): GarbageCollectionReport
    {
        $rootsBefore = $this->backend->rootCount();
        if ($rootsBefore < 0) {
            throw new \UnexpectedValueException('Garbage collector root count cannot be negative.');
        }
        $thresholdBefore = $this->threshold;
        if (!$force && $rootsBefore < $thresholdBefore) {
            return new GarbageCollectionReport(
                collected: false,
                forced: false,
                allocatorCachesReleased: false,
                rootsBefore: $rootsBefore,
                rootsAfter: $rootsBefore,
                cyclesCollected: 0,
                allocatorBytesReleased: 0,
                durationNanoseconds: 0,
                thresholdBefore: $thresholdBefore,
                thresholdAfter: $thresholdBefore,
            );
        }

        $startedAt = $this->backend->monotonicNanoseconds();
        $cycles = $this->backend->collectCycles();
        $allocatorBytes = $releaseAllocatorCaches ? $this->backend->releaseAllocatorCaches() : 0;
        $completedAt = $this->backend->monotonicNanoseconds();
        $rootsAfter = $this->backend->rootCount();
        if ($cycles < 0 || $allocatorBytes < 0 || $rootsAfter < 0 || $completedAt < $startedAt) {
            throw new \UnexpectedValueException('Garbage collector backend returned invalid results.');
        }

        $this->adjustThreshold($cycles, $rootsAfter);
        $duration = $completedAt - $startedAt;
        ++$this->runs;
        $this->totalCollectionNanoseconds = self::saturatingAdd($this->totalCollectionNanoseconds, $duration);

        return new GarbageCollectionReport(
            collected: true,
            forced: $force,
            allocatorCachesReleased: $releaseAllocatorCaches,
            rootsBefore: $rootsBefore,
            rootsAfter: $rootsAfter,
            cyclesCollected: $cycles,
            allocatorBytesReleased: $allocatorBytes,
            durationNanoseconds: $duration,
            thresholdBefore: $thresholdBefore,
            thresholdAfter: $this->threshold,
        );
    }

    private function adjustThreshold(int $cyclesCollected, int $rootsAfter): void
    {
        if ($cyclesCollected < $this->minimumProductiveCycles || $rootsAfter >= $this->threshold) {
            $this->threshold = min($this->maximumThreshold, self::saturatingAdd($this->threshold, $this->thresholdStep));

            return;
        }
        $this->threshold = max($this->minimumThreshold, $this->threshold - $this->thresholdStep);
    }

    private static function saturatingAdd(int $left, int $right): int
    {
        return $right > PHP_INT_MAX - $left ? PHP_INT_MAX : $left + $right;
    }
}
