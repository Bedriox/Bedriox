<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Command;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Server\Command\Default\GarbageCollectionStatus;
use Bedriox\Server\Command\Default\GarbageCollectorCommand;
use Bedriox\Server\Observability\Memory\GarbageCollectionReport;
use Bedriox\Server\Observability\Memory\MemoryPressure;
use Bedriox\Server\Observability\Memory\MemorySnapshot;
use Bedriox\Server\World\ChunkUnloadResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GarbageCollectorCommandTest extends TestCase
{
    public function testBareAndExplicitStatusRenderInjectedImmutableSnapshot(): void
    {
        $statusCalls = 0;
        $status = static function () use (&$statusCalls): GarbageCollectionStatus {
            ++$statusCalls;

            return self::snapshot();
        };
        $command = new GarbageCollectorCommand($status, self::collector(...), self::chunkUnload(...));

        $bare = new GarbageCollectorTestSender();
        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext($bare, 'gc', [])));
        self::assertSame([
            '--------- Bedriox Garbage Collection ---------',
            'Memory: 100.0 MiB used, 128.0 MiB allocated, 256.0 MiB peak, 512.0 MiB limit (25.0%)',
            'Collector: high pressure, 4 runs, 20001-root threshold',
            'Last collection: 12 cycles, 2.0 MiB allocator memory, 2.50 ms',
            'Chunks: 120 loaded, 24 retained, 17 dirty, 31 queued for unload, 48 unloaded total',
            '--------- End Garbage Collection ---------',
        ], $bare->messages);

        $explicit = new GarbageCollectorTestSender();
        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext($explicit, 'gc', ['StAtUs'])));
        self::assertSame($bare->messages, $explicit->messages);
        self::assertSame(2, $statusCalls);
    }

    public function testRunReportsTheInjectedForcedCollectionResult(): void
    {
        $collectionCalls = 0;
        $command = new GarbageCollectorCommand(
            self::snapshot(...),
            static function () use (&$collectionCalls): GarbageCollectionReport {
                ++$collectionCalls;

                return self::collector();
            },
            self::chunkUnload(...),
        );
        $sender = new GarbageCollectorTestSender();

        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext($sender, 'gc', ['run'])));
        self::assertSame(1, $collectionCalls);
        self::assertSame([
            '--------- Garbage Collection Result ---------',
            'Cycles collected: 9',
            'Allocator memory released: 1.0 MiB',
            'Roots: 50 before, 4 after; threshold: 20001 to 10001',
            'Collection time: 1.50 ms; allocator cache cleanup: completed',
            '--------- End Garbage Collection ---------',
        ], $sender->messages);
    }

    public function testChunksReportsBoundedSafeUnloadResultAndPersistenceBackpressure(): void
    {
        $unloadCalls = 0;
        $command = new GarbageCollectorCommand(
            self::snapshot(...),
            self::collector(...),
            static function () use (&$unloadCalls): ChunkUnloadResult {
                ++$unloadCalls;

                return self::chunkUnload();
            },
        );
        $sender = new GarbageCollectorTestSender();

        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext($sender, 'gc', ['chunks'])));
        self::assertSame(1, $unloadCalls);
        self::assertSame([
            '--------- Chunk Cleanup Result ---------',
            'Chunks: 96 examined, 41 unloaded, 7 saves queued',
            'Unload queue remaining: 55',
            'Persistence is saturated; dirty chunks remain queued safely.',
            '--------- End Chunk Cleanup ---------',
        ], $sender->messages);
    }

    public function testInvalidUsageDoesNotInvokeRuntimeCallbacks(): void
    {
        $calls = 0;
        $status = static function () use (&$calls): GarbageCollectionStatus {
            ++$calls;

            return self::snapshot();
        };
        $collect = static function () use (&$calls): GarbageCollectionReport {
            ++$calls;

            return self::collector();
        };
        $chunks = static function () use (&$calls): ChunkUnloadResult {
            ++$calls;

            return self::chunkUnload();
        };
        $command = new GarbageCollectorCommand($status, $collect, $chunks);
        $sender = new GarbageCollectorTestSender();

        self::assertSame(CommandResult::USAGE, $command->execute(new CommandContext($sender, 'gc', ['unknown'])));
        self::assertSame(CommandResult::USAGE, $command->execute(new CommandContext($sender, 'gc', ['run', 'extra'])));
        self::assertSame(0, $calls);
        self::assertSame([], $sender->messages);
    }

    public function testStatusRejectsNegativeCounters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GarbageCollectionStatus(
            new MemorySnapshot(0, 0, 0, 0, 0),
            MemoryPressure::NORMAL,
            -1,
            10_001,
            null,
            0,
            0,
            0,
            0,
            0,
        );
    }

    private static function snapshot(): GarbageCollectionStatus
    {
        return new GarbageCollectionStatus(
            new MemorySnapshot(100 * 1_048_576, 128 * 1_048_576, 256 * 1_048_576, 512 * 1_048_576, 1),
            MemoryPressure::HIGH,
            4,
            20_001,
            new GarbageCollectionReport(true, true, true, 40, 3, 12, 2 * 1_048_576, 2_500_000, 10_001, 20_001),
            120,
            24,
            17,
            31,
            48,
        );
    }

    private static function collector(): GarbageCollectionReport
    {
        return new GarbageCollectionReport(true, true, true, 50, 4, 9, 1_048_576, 1_500_000, 20_001, 10_001);
    }

    private static function chunkUnload(): ChunkUnloadResult
    {
        return new ChunkUnloadResult(96, 41, 7, true, 55);
    }
}

final class GarbageCollectorTestSender implements CommandSender
{
    /** @var list<string> */
    public array $messages = [];

    public function type(): CommandSenderType
    {
        return CommandSenderType::CONSOLE;
    }

    public function name(): string
    {
        return 'Console';
    }

    public function sendMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }
}
