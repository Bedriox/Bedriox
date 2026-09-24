<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkUnloadManager;
use PHPUnit\Framework\TestCase;

final class ChunkUnloadManagerTest extends TestCase
{
    public function testGracePeriodAndRetainCancellationAreDeterministic(): void
    {
        $now = 10_000;
        $manager = new ChunkUnloadManager(500, 4, static function () use (&$now): int {
            return $now;
        });
        $position = new ChunkPosition(2, -3);

        self::assertTrue($manager->queue($position));
        self::assertFalse($manager->queue($position));
        self::assertSame([], $manager->due(4, 1_000));
        $now += 500;
        self::assertSame([$position], $manager->due(4, 1_000));
        self::assertTrue($manager->cancel($position));
        self::assertFalse($manager->contains($position));
        self::assertSame(0, $manager->count());
    }

    public function testDueSelectionIsBoundedWithoutRemovingDeferredEntries(): void
    {
        $manager = new ChunkUnloadManager(0, 4, static fn(): int => 1_000);
        $positions = [new ChunkPosition(0, 0), new ChunkPosition(1, 0), new ChunkPosition(2, 0)];
        foreach ($positions as $position) {
            $manager->queue($position);
        }

        self::assertSame(array_slice($positions, 0, 2), $manager->due(2, 1_000));
        self::assertTrue($manager->defer($positions[0]));
        self::assertSame([$positions[1], $positions[2]], $manager->due(2, 1_000));
        self::assertSame(3, $manager->count());
    }

    public function testDueSelectionStopsWhenElapsedTimeBudgetIsReached(): void
    {
        $clock = new class {
            public int $now = 0;
            public bool $advance = false;
        };
        $manager = new ChunkUnloadManager(0, 4, static function () use ($clock): int {
            if ($clock->advance) {
                $clock->now += 2_000;
            }

            return $clock->now;
        });
        $positions = [new ChunkPosition(0, 0), new ChunkPosition(1, 0), new ChunkPosition(2, 0)];
        foreach ($positions as $position) {
            $manager->queue($position);
        }
        $clock->advance = true;

        self::assertSame([$positions[0]], $manager->due(3, 1));
        self::assertSame(3, $manager->count());
    }
}
