<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ChunkRepositoryTest extends TestCase
{
    public function testCachesChunksAndEvictsLeastRecentlyUsedEntry(): void
    {
        $repository = new ChunkRepository(2);
        $loads = 0;
        $loader = static function (ChunkPosition $position) use (&$loads): Chunk {
            ++$loads;

            return new Chunk($position, new InternalBlockStateId(0), []);
        };
        $zero = new ChunkPosition(0, 0);
        $one = new ChunkPosition(1, 0);
        $two = new ChunkPosition(2, 0);

        self::assertSame($repository->get($zero, $loader), $repository->get($zero, $loader));
        $repository->get($one, $loader);
        $repository->get($zero, $loader); // zero is now newer than one
        $repository->get($two, $loader);

        self::assertSame(3, $loads);
        self::assertTrue($repository->contains($zero));
        self::assertFalse($repository->contains($one));
        self::assertTrue($repository->contains($two));
        self::assertSame(2, $repository->count());
    }

    public function testRetainedChunksCannotBeEvictedUntilReleased(): void
    {
        $repository = new ChunkRepository(1);
        $loader = static fn(ChunkPosition $position): Chunk => new Chunk($position, new InternalBlockStateId(0), []);
        $retained = new ChunkPosition(0, 0);
        $repository->retain($retained, $loader);

        try {
            $repository->get(new ChunkPosition(1, 0), $loader);
            self::fail('Cache evicted a retained chunk.');
        } catch (OverflowException) {
            self::addToAssertionCount(1);
        }
        $repository->release($retained);
        $repository->get(new ChunkPosition(1, 0), $loader);
        self::assertFalse($repository->contains($retained));
    }

    public function testLoaderMustReturnRequestedPosition(): void
    {
        $repository = new ChunkRepository(1);

        $this->expectException(UnexpectedValueException::class);
        $repository->get(
            new ChunkPosition(0, 0),
            static fn(): Chunk => new Chunk(new ChunkPosition(1, 0), new InternalBlockStateId(0), []),
        );
    }

    public function testBoundsAndInvalidReleaseFailDeterministically(): void
    {
        foreach ([0, 100_001] as $capacity) {
            try {
                new ChunkRepository($capacity);
                self::fail('Invalid cache capacity was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        (new ChunkRepository(1))->release(new ChunkPosition(0, 0));
    }
}
