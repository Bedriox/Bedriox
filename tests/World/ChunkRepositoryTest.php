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

    public function testDirtyChunkIsSavedBeforeEviction(): void
    {
        $air = new InternalBlockStateId(0);
        $solid = new InternalBlockStateId(1);
        $repository = new ChunkRepository(1);
        $first = new ChunkPosition(0, 0);
        $repository->get($first, static fn(ChunkPosition $position): Chunk => new Chunk($position, $air, []));
        $repository->replace($repository->get($first, self::failLoader(...))->withBlockState(1, 0, 1, $solid));
        $saved = [];

        $repository->get(
            new ChunkPosition(1, 0),
            static fn(ChunkPosition $position): Chunk => new Chunk($position, $air, []),
            static function (Chunk $chunk) use (&$saved): void {
                $saved[] = [$chunk->position->key(), $chunk->revision];
            },
        );

        self::assertSame([['0:0', 1]], $saved);
        self::assertFalse($repository->contains($first));
    }

    public function testFailedEvictionSavePreservesCachedChunk(): void
    {
        $air = new InternalBlockStateId(0);
        $solid = new InternalBlockStateId(1);
        $repository = new ChunkRepository(1);
        $first = new ChunkPosition(0, 0);
        $second = new ChunkPosition(1, 0);
        $repository->get($first, static fn(ChunkPosition $position): Chunk => new Chunk($position, $air, []));
        $repository->replace($repository->get($first, self::failLoader(...))->withBlockState(1, 0, 1, $solid));

        try {
            $repository->get(
                $second,
                static fn(ChunkPosition $position): Chunk => new Chunk($position, $air, []),
                static function (): never {
                    throw new \RuntimeException('Injected save failure.');
                },
            );
            self::fail('A failed save allowed dirty eviction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected save failure.', $exception->getMessage());
        }

        self::assertTrue($repository->contains($first));
        self::assertFalse($repository->contains($second));
        self::assertSame(1, $repository->dirtyCount());
    }

    public function testBoundedAutosaveUsesOldestDirtyOrder(): void
    {
        $air = new InternalBlockStateId(0);
        $solid = new InternalBlockStateId(1);
        $repository = new ChunkRepository(2);
        $first = new ChunkPosition(0, 0);
        $second = new ChunkPosition(1, 0);
        foreach ([$first, $second] as $position) {
            $repository->get($position, static fn(ChunkPosition $requested): Chunk => new Chunk($requested, $air, []));
            $repository->replace(
                $repository->get($position, self::failLoader(...))->withBlockState(1, 0, 1, $solid),
            );
        }
        $saved = [];

        self::assertSame(1, $repository->saveDirty(1, static function (Chunk $chunk) use (&$saved): void {
            $saved[] = $chunk->position->key();
        }));

        self::assertSame(['0:0'], $saved);
        self::assertSame(1, $repository->dirtyCount());
    }

    public function testSaveAcknowledgesOnlyTheCapturedRevision(): void
    {
        $air = new InternalBlockStateId(0);
        $firstState = new InternalBlockStateId(1);
        $secondState = new InternalBlockStateId(2);
        $position = new ChunkPosition(0, 0);
        $repository = new ChunkRepository(1);
        $repository->get($position, static fn(ChunkPosition $requested): Chunk => new Chunk($requested, $air, []));
        $repository->replace(
            $repository->get($position, self::failLoader(...))->withBlockState(1, 0, 1, $firstState),
        );

        $repository->saveDirty(1, function (Chunk $snapshot) use ($repository, $secondState): void {
            $repository->replace($snapshot->withBlockState(2, 0, 2, $secondState));
        });

        $current = $repository->get($position, self::failLoader(...));
        self::assertSame(2, $current->revision);
        self::assertSame(1, $current->persistedRevision);
        self::assertTrue($current->isDirty());
    }

    private static function failLoader(ChunkPosition $_position): never
    {
        throw new \LogicException('Cached chunk unexpectedly invoked its loader.');
    }
}
