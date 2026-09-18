<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Server\Observability\CrashContext;
use Bedriox\Server\Observability\CrashHandler;
use Bedriox\Server\Observability\CrashPlayer;
use Bedriox\Server\Observability\CrashReporter;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\MutableCrashContextProvider;
use Bedriox\Server\Observability\ServerLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CrashHandlerTest extends TestCase
{
    public function testMutableProviderMergesRuntimeAndPluginContext(): void
    {
        $provider = new MutableCrashContextProvider();
        $player = new CrashPlayer('Alex', 'uuid', 'xuid', '192.0.2.10:19132', 'Ios', 'SPAWNED');

        $provider->publishPlugin('ExamplePlugin 1.0.0 event-listener');
        $provider->publishRuntime(81, [$player], $player);

        $context = $provider->current();
        self::assertSame(81, $context->tick);
        self::assertSame([$player], $context->players);
        self::assertSame($player, $context->involvedPlayer);
        self::assertSame('ExamplePlugin 1.0.0 event-listener', $context->pluginAttribution);

        $provider->publishPlugin(null);
        self::assertNull($provider->current()->pluginAttribution);
        self::assertSame(81, $provider->current()->tick);
    }

    public function testReadsTheLatestBoundedContextAndReportsOnlyOnce(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-handler-' . bin2hex(random_bytes(6));
        $logger = new ServerLogger(static function (string $line): void {}, LogLevel::DEBUG, false, false, null);
        $provider = new MutableCrashContextProvider();
        $provider->update(new CrashContext(tick: 77, pluginAttribution: 'ExamplePlugin (confirmed)'));
        $handler = new CrashHandler(new CrashReporter($directory, $logger), $logger, $provider->current(...), 1_024);
        try {
            $path = $handler->capture(new RuntimeException('failure'));
            self::assertIsString($path);
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString('Uptime/tick: tick 77', $contents);
            self::assertStringContainsString('ExamplePlugin (confirmed)', $contents);
            self::assertNull($handler->capture(new RuntimeException('second failure')));
            self::assertCount(1, glob($directory . DIRECTORY_SEPARATOR . '*.txt') ?: []);
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}
