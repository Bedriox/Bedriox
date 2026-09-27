<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Server\Observability\BackgroundLogWriter;
use Bedriox\Server\Observability\BoundedLogQueue;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\LogQueueSubmission;
use PHPUnit\Framework\TestCase;

final class BackgroundLogWriterTest extends TestCase
{
    public function testSubprocessHandshakesAndFlushesOrderedLines(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-log-process-' . bin2hex(random_bytes(6));
        $path = $directory . DIRECTORY_SEPARATOR . 'server.log';
        $writer = null;
        try {
            $writer = BackgroundLogWriter::start(
                'test-version',
                $path,
                65_536,
                1,
                new BoundedLogQueue(8, 4_096, 1_024, 2, 1_024),
            );
            self::assertTrue($writer->snapshot()->available);
            self::assertSame(LogQueueSubmission::ACCEPTED, $writer->enqueue(1, LogLevel::INFO, 'first'));
            self::assertSame(LogQueueSubmission::ACCEPTED, $writer->enqueue(2, LogLevel::ERROR, 'second'));

            self::assertTrue($writer->flush(5_000));
            self::assertSame(2, $writer->snapshot()->acknowledged);
            self::assertSame("first\nsecond\n", str_replace("\r\n", "\n", (string) file_get_contents($path)));
            self::assertTrue($writer->shutdown(5_000));
            $writer = null;
        } finally {
            $writer?->shutdown(1_000);
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testEnqueueRemainsBoundedBeforeProcessPolling(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-log-process-' . bin2hex(random_bytes(6));
        $path = $directory . DIRECTORY_SEPARATOR . 'server.log';
        $writer = null;
        try {
            $writer = BackgroundLogWriter::start(
                'test-version',
                $path,
                65_536,
                1,
                new BoundedLogQueue(2, 128, 64, 1, 32),
            );
            self::assertSame(LogQueueSubmission::ACCEPTED, $writer->enqueue(1, LogLevel::INFO, 'routine'));
            self::assertSame(LogQueueSubmission::DROPPED, $writer->enqueue(2, LogLevel::INFO, 'dropped'));
            self::assertSame(LogQueueSubmission::ACCEPTED, $writer->enqueue(3, LogLevel::CRITICAL, 'reserved'));
            self::assertSame(1, $writer->snapshot()->queue->droppedRoutine);
        } finally {
            $writer?->shutdown(5_000);
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testOnePollWindowPipelinesManyOrderedLines(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-log-pipeline-' . bin2hex(random_bytes(6));
        $path = $directory . DIRECTORY_SEPARATOR . 'server.log';
        $writer = null;
        try {
            $writer = BackgroundLogWriter::start(
                'test-version',
                $path,
                65_536,
                1,
                new BoundedLogQueue(256, 65_536, 1_024, 8, 4_096),
            );
            for ($sequence = 1; $sequence <= 128; ++$sequence) {
                self::assertSame(
                    LogQueueSubmission::ACCEPTED,
                    $writer->enqueue($sequence, LogLevel::DEBUG, 'line-' . $sequence),
                );
            }

            self::assertTrue($writer->flush(5_000));
            $snapshot = $writer->snapshot();
            self::assertSame(128, $snapshot->acknowledged);
            self::assertSame(0, $snapshot->queue->queued);
            self::assertSame(0, $snapshot->queue->droppedRoutine);
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines);
            self::assertSame('line-1', $lines[0]);
            self::assertSame('line-128', $lines[127]);
        } finally {
            $writer?->shutdown(1_000);
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testFailedWriterRetainsUnacknowledgedLineForSynchronousFallback(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-log-process-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $writer = null;
        try {
            $writer = BackgroundLogWriter::start(
                'test-version',
                $directory,
                65_536,
                1,
                new BoundedLogQueue(4, 1_024, 256, 1, 256),
            );
            $writer->enqueue(1, LogLevel::ERROR, 'must remain pending');

            self::assertFalse($writer->flush(5_000));
            $snapshot = $writer->snapshot();
            self::assertFalse($snapshot->available);
            self::assertSame(1, $snapshot->queue->queued);
            self::assertSame(1, $snapshot->queue->writeFailures);
        } finally {
            $writer?->shutdown(1_000);
            @rmdir($directory);
        }
    }
}
