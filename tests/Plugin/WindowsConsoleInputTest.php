<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Plugin\Command\WindowsConsoleInput;
use PHPUnit\Framework\TestCase;

final class WindowsConsoleInputTest extends TestCase
{
    public function testPollingReturnsWhileHelperWaitsForInput(): void
    {
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $producer = proc_open(
            [PHP_BINARY, '-n', '-r', 'usleep(1000000);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($producer);
        fclose($pipes[0]);

        $input = WindowsConsoleInput::start($pipes[1], $this->logger());
        $startedAt = hrtime(true);
        self::assertSame([], $input->readAvailable());
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $closeStartedAt = hrtime(true);
        $input->close();
        $closeSeconds = (hrtime(true) - $closeStartedAt) / 1_000_000_000;
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($producer);
        proc_close($producer);

        self::assertLessThan(0.5, $elapsedSeconds);
        self::assertLessThan(0.9, $closeSeconds);
    }

    public function testHelperTransfersCompleteLinesWithoutReadingInServerProcess(): void
    {
        $source = fopen('php://temp', 'r+');
        self::assertIsResource($source);
        fwrite($source, "first\r\nsecond\n");
        rewind($source);

        $input = WindowsConsoleInput::start($source, $this->logger());
        $lines = [];
        $deadline = hrtime(true) + 2_000_000_000;
        do {
            $lines = [...$lines, ...$input->readAvailable()];
            if (count($lines) === 2) {
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $deadline);

        $input->close();
        $input->close();
        fclose($source);

        self::assertSame(['first', 'second'], $lines);
    }

    private function logger(): ServerLogger
    {
        return new ServerLogger(static function (): void {}, LogLevel::DEBUG, true, false, null);
    }
}
