<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\World\WorldCreatedEvent;
use Bedriox\Api\Event\World\WorldCreateEvent;
use Bedriox\Api\Event\World\WorldLoadEvent;
use Bedriox\Api\World\World as PublicWorld;
use Bedriox\Api\World\WorldCreationOptions;
use Bedriox\Api\World\WorldOperationFailureCode;
use Bedriox\Api\World\WorldOperationState;
use Bedriox\Server\Runtime\ManagedWorldRuntime;
use Bedriox\Server\Runtime\OpenedWorld;
use Bedriox\Server\Runtime\RuntimeWorldManager;
use Bedriox\Server\Runtime\WorldOperationQueue;
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
use PHPUnit\Framework\TestCase;

final class RuntimeWorldManagerTest extends TestCase
{
    public function testIdenticalOperationsShareOneLifecycleExecution(): void
    {
        $queue = new WorldOperationQueue();
        $created = 0;
        $events = [];
        $manager = self::manager($queue, $events, $created);

        $first = $manager->create('arena');
        $second = $manager->create('ARENA');
        $callbacks = [];
        $first->onComplete(static function () use (&$callbacks): void {
            $callbacks[] = 'first';
        });
        $second->onComplete(static function () use (&$callbacks): void {
            $callbacks[] = 'second';
        });

        self::assertSame($first, $second);
        self::assertSame(1, $queue->pendingCount());
        self::assertSame(1, $queue->poll());
        self::assertSame([], $callbacks);
        self::assertSame(0, $queue->poll());
        self::assertSame(['first', 'second'], $callbacks);
        self::assertSame(1, $created);
        self::assertSame(WorldOperationState::SUCCEEDED, $first->state());
        self::assertSame([WorldCreateEvent::class, WorldCreatedEvent::class], $events);
    }

    public function testConflictingOperationForSameWorldIsRejectedWithoutDispatchingItsPreEvent(): void
    {
        $queue = new WorldOperationQueue();
        $created = 0;
        $events = [];
        $manager = self::manager($queue, $events, $created);

        $create = $manager->create('arena');
        $load = $manager->load('arena');

        self::assertNotSame($create, $load);
        self::assertSame(2, $queue->poll(2));
        self::assertSame(WorldOperationState::SUCCEEDED, $create->state());
        self::assertSame(WorldOperationState::REJECTED, $load->state());
        self::assertSame(WorldOperationFailureCode::BUSY, $load->result()?->failure?->code);
        self::assertNotContains(WorldLoadEvent::class, $events);
    }

    public function testCoreEligibilityFailuresOccurBeforeCancellablePreEvents(): void
    {
        $queue = new WorldOperationQueue();
        $created = 0;
        $events = [];
        $manager = self::manager($queue, $events, $created);
        $default = $manager->getDefault();

        $create = $manager->create('world');
        self::assertSame(1, $queue->poll());
        $load = $manager->load('world');
        self::assertSame(1, $queue->poll());
        $unload = $manager->unload($default);
        self::assertSame(1, $queue->poll());
        $save = $manager->save(new PublicWorld('world', $default->loadGeneration() + 1));
        self::assertSame(1, $queue->poll());

        self::assertSame(WorldOperationFailureCode::ALREADY_EXISTS, $create->result()?->failure?->code);
        self::assertSame(WorldOperationFailureCode::ALREADY_EXISTS, $load->result()?->failure?->code);
        self::assertSame(WorldOperationFailureCode::DEFAULT_WORLD, $unload->result()?->failure?->code);
        self::assertSame(WorldOperationFailureCode::STALE_HANDLE, $save->result()?->failure?->code);
        self::assertSame([], $events);
        self::assertSame(0, $created);

        $capacityQueue = new WorldOperationQueue();
        $capacityEvents = [];
        $capacityCreated = 0;
        $capacityManager = self::manager($capacityQueue, $capacityEvents, $capacityCreated, 1);
        $capacity = $capacityManager->create('arena');
        self::assertSame(1, $capacityQueue->poll());
        self::assertSame(WorldOperationFailureCode::CAPACITY, $capacity->result()?->failure?->code);
        self::assertSame([], $capacityEvents);
        self::assertSame(0, $capacityCreated);
    }

    /**
     * @param list<class-string<Event>> $events
     */
    private static function manager(
        WorldOperationQueue $queue,
        array &$events,
        int &$created,
        int $maximumLoadedWorlds = 64,
    ): RuntimeWorldManager {
        $runtimes = new WorldRuntimeManager(
            'world',
            self::runtime(new PublicWorld('world', 1)),
            $maximumLoadedWorlds,
        );

        return new RuntimeWorldManager(
            $runtimes,
            $queue,
            static function (PublicWorld $handle, WorldCreationOptions $options) use (&$created): ManagedWorldRuntime {
                ++$created;

                return self::runtime($handle);
            },
            static fn(PublicWorld $handle): ManagedWorldRuntime => self::runtime($handle),
            static function (Event $event) use (&$events): Event {
                $events[] = $event::class;

                return $event;
            },
        );
    }

    private static function runtime(PublicWorld $handle): ManagedWorldRuntime
    {
        $metadata = new WorldMetadata($handle->id(), 0);
        $world = new World($metadata, new RuntimeWorldManagerGenerator(), new ChunkRepository(16));
        $opened = new OpenedWorld($world, new WorldData($metadata, 'test', new SpawnPosition(0, 64, 0)));
        $simulation = new WorldSimulation();

        return new ManagedWorldRuntime(
            $handle,
            $opened,
            $simulation,
            new FixedRateWorldLoop($simulation, new RuntimeWorldManagerClock()),
        );
    }
}

final class RuntimeWorldManagerClock implements SimulationClock
{
    public function nowNanoseconds(): int
    {
        return 1_000_000;
    }
}

final readonly class RuntimeWorldManagerGenerator implements WorldGenerator
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
