<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\World\World as PublicWorld;
use Bedriox\Server\Runtime\ManagedWorldRuntime;
use Bedriox\Server\Runtime\OpenedWorld;
use Bedriox\Server\Runtime\WorldRuntimeManager;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\SimulationClock;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldGenerator;
use Bedriox\Server\World\WorldMetadata;
use LogicException;
use PHPUnit\Framework\TestCase;

final class WorldRuntimeManagerTest extends TestCase
{
    public function testLoadedRuntimeIsCanonicalAndDuplicateLoadsAreCoalesced(): void
    {
        $manager = new WorldRuntimeManager('world', self::runtime(new PublicWorld('world', 1)));
        $loads = 0;
        $mines = $manager->load('mines', static function (PublicWorld $handle) use (&$loads): ManagedWorldRuntime {
            ++$loads;

            return self::runtime($handle);
        });
        $again = $manager->load('MINES', static function () use (&$loads): never {
            ++$loads;
            throw new \RuntimeException('Duplicate load unexpectedly reopened storage.');
        });

        self::assertSame($mines, $again);
        self::assertSame(1, $loads);
        self::assertSame(2, $manager->count());
        self::assertSame(['world', 'mines'], array_map(
            static fn(ManagedWorldRuntime $runtime): string => $runtime->handle->id(),
            $manager->loaded(),
        ));
    }

    public function testUnloadProtectsDefaultAndRejectsStaleHandlesAfterReload(): void
    {
        $manager = new WorldRuntimeManager('world', self::runtime(new PublicWorld('world', 1)));

        $this->expectException(LogicException::class);
        try {
            $manager->unload($manager->default()->handle);
        } finally {
            $first = $manager->load('mines', self::runtime(...));
            $manager->unload($first->handle);
            $second = $manager->load('mines', self::runtime(...));
            self::assertSame(2, $second->handle->loadGeneration());
            try {
                $manager->save($first->handle);
                self::fail('A stale world handle was accepted.');
            } catch (LogicException $failure) {
                self::assertStringContainsString('earlier load generation', $failure->getMessage());
            }
        }
    }

    public function testWorldPollingUsesOneCadenceAndFairOrdering(): void
    {
        $clock = new RuntimeManagerClock();
        $manager = new WorldRuntimeManager('world', self::runtime(new PublicWorld('world', 1), $clock));
        $manager->load('mines', static fn(PublicWorld $handle): ManagedWorldRuntime => self::runtime($handle, $clock));

        self::assertSame(50_000_000, $manager->nanosecondsUntilNextTick());
        $clock->nanoseconds += 50_000_000;
        $first = $manager->poll();
        $second = $manager->poll();

        self::assertSame(['world', 'mines'], array_keys($first));
        self::assertSame(['mines', 'world'], array_keys($second));
        self::assertCount(1, $first['world']);
        self::assertCount(1, $first['mines']);
        self::assertCount(0, $second['world']);
        self::assertCount(0, $second['mines']);
        self::assertSame(1, $manager->default()->simulation->snapshot()->tick);
        self::assertSame(1, $manager->get('mines')?->simulation->snapshot()->tick);
    }

    public function testSchedulerBoundaryRunsOnceBeforeEveryWorldTick(): void
    {
        $clock = new RuntimeManagerClock();
        $manager = new WorldRuntimeManager('world', self::runtime(new PublicWorld('world', 1), $clock));
        $manager->load('mines', static fn(PublicWorld $handle): ManagedWorldRuntime => self::runtime($handle, $clock));
        $observed = [];
        $clock->nanoseconds += 50_000_000;

        $manager->poll(function () use ($manager, &$observed): void {
            $observed[] = [
                $manager->default()->simulation->snapshot()->tick,
                $manager->get('mines')?->simulation->snapshot()->tick,
            ];
        });

        self::assertSame([[0, 0]], $observed);
        self::assertSame(1, $manager->default()->simulation->snapshot()->tick);
        self::assertSame(1, $manager->get('mines')?->simulation->snapshot()->tick);
    }

    public function testCapacityAndInvalidIdsFailBeforeLoading(): void
    {
        $manager = new WorldRuntimeManager('world', self::runtime(new PublicWorld('world', 1)), 1);
        $called = false;

        try {
            $manager->load('other', static function () use (&$called): never {
                $called = true;
                throw new \RuntimeException('Loader should not run.');
            });
            self::fail('Capacity exhaustion was accepted.');
        } catch (LogicException) {
            self::assertFalse($called);
        }

        $this->expectException(\InvalidArgumentException::class);
        $manager->get('../outside');
    }

    public function testChunkAccountingAggregatesEveryLoadedWorld(): void
    {
        $default = self::runtime(new PublicWorld('world', 1));
        $manager = new WorldRuntimeManager('world', $default);
        $mines = $manager->load('mines', self::runtime(...));

        $default->opened->world->chunk(new ChunkPosition(0, 0));
        $mines->opened->world->chunk(new ChunkPosition(2, 3));
        $mines->opened->world->chunk(new ChunkPosition(3, 3));

        self::assertSame(3, $manager->loadedChunkCount());
        self::assertSame(0, $manager->dirtyChunkCount());
        self::assertSame(0, $manager->generatingChunkCount());
        $snapshot = $manager->chunkRepositorySnapshot();
        self::assertSame(32, $snapshot->capacity);
        self::assertSame(3, $snapshot->loaded);
        self::assertSame(3, $snapshot->misses);
    }

    private static function runtime(PublicWorld $handle, ?RuntimeManagerClock $clock = null): ManagedWorldRuntime
    {
        $metadata = new WorldMetadata($handle->id(), 0);
        $generator = new RuntimeManagerGenerator();
        $world = new World($metadata, $generator, new ChunkRepository(16));
        $opened = new OpenedWorld($world, new WorldData($metadata, 'test', new SpawnPosition(0, 64, 0)));
        $simulation = new WorldSimulation();

        return new ManagedWorldRuntime(
            $handle,
            $opened,
            $simulation,
            new FixedRateWorldLoop($simulation, $clock ?? new RuntimeManagerClock()),
        );
    }
}

final class RuntimeManagerClock implements SimulationClock
{
    public int $nanoseconds = 1_000_000;

    public function nowNanoseconds(): int
    {
        return $this->nanoseconds;
    }
}

final readonly class RuntimeManagerGenerator implements WorldGenerator
{
    public function name(): string
    {
        return 'test';
    }

    public function generate(ChunkPosition $position): Chunk
    {
        return new Chunk($position, new \Bedriox\Server\World\Block\InternalBlockStateId(0), []);
    }

    public function defaultSpawn(): SpawnPosition
    {
        return new SpawnPosition(0, 64, 0);
    }
}
