<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Server\Observability\CrashContext;
use Bedriox\Server\Observability\CrashPlayer;
use Bedriox\Server\Observability\CrashReporter;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\ServerLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CrashReporterTest extends TestCase
{
    public function testWritesUniqueAtomicReportsWithRequestedPlayerIdentifiersAndRedaction(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-crash-' . bin2hex(random_bytes(6));
        $root = dirname(__DIR__, 2);
        $logger = new ServerLogger(static function (string $line): void {}, LogLevel::DEBUG, false, false, null);
        $logger->info('Before crash token=private-value');
        $reporter = new CrashReporter($directory, $logger, true, $root);
        $player = new CrashPlayer('Steve', '123e4567-e89b-12d3-a456-426614174000', '2533274000000000', '192.168.1.13:54321', 'iOS', 'SPAWNED');
        try {
            $failure = new RuntimeException('password=private-value at ' . $root . DIRECTORY_SEPARATOR . 'src');
            $first = $reporter->report($failure, new CrashContext(42, [$player], $player, 'ExamplePlugin 1.0.0 (confirmed)'));
            $second = $reporter->report($failure, new CrashContext());

            self::assertNotSame($first, $second);
            self::assertFileExists($first);
            self::assertSame([], glob($directory . DIRECTORY_SEPARATOR . '*.tmp-*') ?: []);
            $contents = file_get_contents($first);
            self::assertIsString($contents);
            self::assertStringContainsString('BEDRIOX CRASH REPORT - SENSITIVE LOCAL DIAGNOSTIC', $contents);
            self::assertStringContainsString('name=Steve', $contents);
            self::assertStringContainsString('uuid=123e4567-e89b-12d3-a456-426614174000', $contents);
            self::assertStringContainsString('remote=192.168.1.13:54321', $contents);
            self::assertStringContainsString('ExamplePlugin 1.0.0 (confirmed)', $contents);
            self::assertStringContainsString('{server}', $contents);
            self::assertStringNotContainsString('private-value', $contents);
            self::assertStringNotContainsString($root, $contents);
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function testCanExcludePlayerIdentifiers(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-crash-' . bin2hex(random_bytes(6));
        $logger = new ServerLogger(static function (string $line): void {}, LogLevel::INFO, false, false, null);
        $reporter = new CrashReporter($directory, $logger, false);
        $player = new CrashPlayer('PrivateName', 'private-uuid', 'private-xuid', '203.0.113.1:19132', 'iOS', 'SPAWNED');
        try {
            $path = $reporter->report(new RuntimeException('failure'), new CrashContext(players: [$player], involvedPlayer: $player));
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString('identifiers disabled; phase=SPAWNED', $contents);
            self::assertStringNotContainsString('PrivateName', $contents);
            self::assertStringNotContainsString('203.0.113.1', $contents);
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}
