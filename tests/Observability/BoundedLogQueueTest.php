<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Server\Observability\BoundedLogQueue;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\LogQueueSubmission;
use Bedriox\Server\Observability\OrderedLogWriterService;
use Bedriox\Server\Observability\RotatingFileLog;
use LogicException;
use PHPUnit\Framework\TestCase;

final class BoundedLogQueueTest extends TestCase
{
    public function testRoutineTrafficCannotConsumeHighSeverityReserve(): void
    {
        $queue = new BoundedLogQueue(3, 120, 60, 1, 40);

        self::assertSame(LogQueueSubmission::ACCEPTED, $queue->enqueue(1, LogLevel::INFO, 'first'));
        self::assertSame(LogQueueSubmission::ACCEPTED, $queue->enqueue(2, LogLevel::WARNING, 'second'));
        self::assertSame(LogQueueSubmission::DROPPED, $queue->enqueue(3, LogLevel::DEBUG, 'routine-full'));
        self::assertSame(LogQueueSubmission::ACCEPTED, $queue->enqueue(4, LogLevel::ERROR, 'important'));

        $snapshot = $queue->snapshot();
        self::assertSame(3, $snapshot->queued);
        self::assertSame(1, $snapshot->droppedRoutine);
        self::assertSame(0, $snapshot->droppedHighSeverity);
    }

    public function testAcknowledgementIsStrictlyOrdered(): void
    {
        $queue = new BoundedLogQueue(3, 128, 64, 1, 32);
        $queue->enqueue(10, LogLevel::INFO, 'first');
        $queue->enqueue(11, LogLevel::INFO, 'second');

        $this->expectException(LogicException::class);
        $queue->acknowledge(11);
    }

    public function testWriterDrainsFormattedLinesInOrder(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-background-log-' . bin2hex(random_bytes(6));
        $path = $directory . DIRECTORY_SEPARATOR . 'server.log';
        try {
            $service = new OrderedLogWriterService(
                new BoundedLogQueue(8, 2_048, 512, 2, 512),
                new RotatingFileLog($path, 65_536, 1),
            );
            $service->enqueue(1, LogLevel::INFO, 'line one');
            $service->enqueue(2, LogLevel::CRITICAL, 'line two');

            self::assertSame(2, $service->poll(2));
            self::assertSame("line one\nline two\n", str_replace("\r\n", "\n", (string) file_get_contents($path)));
            self::assertSame(0, $service->snapshot()->queued);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
