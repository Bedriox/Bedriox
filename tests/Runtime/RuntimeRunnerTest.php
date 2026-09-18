<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Runtime\RuntimeDriver;
use Bedriox\Server\Runtime\RuntimeRunner;
use Bedriox\Server\Runtime\RuntimeSleeper;
use PHPUnit\Framework\TestCase;

final class RuntimeRunnerTest extends TestCase
{
    public function testGracefulStopClosesExactlyOnce(): void
    {
        $driver = new FakeRuntimeDriver();
        $sleeper = new FakeRuntimeSleeper();
        $polls = 0;
        $result = (new RuntimeRunner($driver, $sleeper))->run(static function () use (&$polls): bool {
            return $polls++ === 2;
        });
        self::assertSame(0, $result);
        self::assertSame(2, $driver->polls);
        self::assertSame(2, $sleeper->calls);
        self::assertSame(1, $driver->closes);
    }

    public function testRuntimeFailureReturnsFailureAndStillCloses(): void
    {
        $driver = new FakeRuntimeDriver(false);
        $result = (new RuntimeRunner($driver, new FakeRuntimeSleeper()))->run(static fn(): bool => false);
        self::assertSame(1, $result);
        self::assertSame(1, $driver->polls);
        self::assertSame(1, $driver->closes);
    }

    public function testThrownRuntimeFailureIsLoggedWithoutExceptionMessage(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $driver = new ThrowingRuntimeDriver();

        $result = (new RuntimeRunner($driver, new FakeRuntimeSleeper(), $diagnostics))->run(static fn(): bool => false);

        self::assertSame(1, $result);
        self::assertSame(1, $driver->closes);
        self::assertCount(1, $lines);
        self::assertStringContainsString('"event":"runtime.runner_failed"', $lines[0]);
        self::assertStringContainsString('RuntimeException', $lines[0]);
        self::assertStringNotContainsString('top-secret-message', $lines[0]);
    }
}

final class ThrowingRuntimeDriver implements RuntimeDriver
{
    public int $closes = 0;

    public function poll(): bool
    {
        throw new \RuntimeException('top-secret-message');
    }

    public function close(): void
    {
        ++$this->closes;
    }
}

final class FakeRuntimeDriver implements RuntimeDriver
{
    public int $polls = 0;
    public int $closes = 0;
    public function __construct(private readonly bool $pollResult = true) {}
    public function poll(): bool
    {
        ++$this->polls;
        return $this->pollResult;
    }
    public function close(): void
    {
        ++$this->closes;
    }
}

final class FakeRuntimeSleeper implements RuntimeSleeper
{
    public int $calls = 0;
    public function idle(): void
    {
        ++$this->calls;
    }
}
