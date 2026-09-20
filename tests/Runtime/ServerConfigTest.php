<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Runtime\ServerConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerConfigTest extends TestCase
{
    public function testDefaultsToFullAuthenticationAndAcceptsBoundedOverrides(): void
    {
        $defaults = ServerConfig::fromArguments([]);
        self::assertSame(AuthenticationMode::FULL, $defaults->authenticationMode);
        self::assertSame(LogLevel::INFO, $defaults->loggingLevel);
        self::assertTrue($defaults->loggingFile);
        self::assertTrue($defaults->consoleEnabled);
        self::assertTrue($defaults->crashReportIncludePlayerIdentifiers);
        self::assertSame(6_000, $defaults->levelAutosaveIntervalTicks);
        self::assertSame(8, $defaults->chunksSavePerTick);
        self::assertSame(6_000, $defaults->playersAutosaveIntervalTicks);
        self::assertSame(8, $defaults->playersSavePerTick);
        self::assertSame(40, $defaults->movementRewindHistorySize);
        self::assertSame('default', $defaults->levelGenerator);
        self::assertSame(1, $defaults->chunksGeneratePerTick);

        $config = ServerConfig::fromArguments([
            '--bind=127.0.0.1',
            '--port=19133',
            '--name=Private Test',
            '--max-players=12',
            '--auth=SELF_SIGNED',
        ]);
        self::assertSame('127.0.0.1', $config->bindAddress);
        self::assertSame(19_133, $config->port);
        self::assertSame('Private Test', $config->serverName);
        self::assertSame(12, $config->maximumPlayers);
        self::assertSame(AuthenticationMode::SELF_SIGNED, $config->authenticationMode);

        $streaming = ServerConfig::fromArguments([
            '--motd=Flat development world',
            '--level-name=flatland',
            '--seed=-42',
            '--view-distance=8',
            '--spawn-radius=6',
            '--chunks-send-per-tick=7',
            '--chunks-generate-per-tick=5',
            '--chunks-cache-limit=6000',
            '--level-autosave-interval-ticks=1200',
            '--chunks-save-per-tick=12',
            '--players-autosave-interval-ticks=1400',
            '--players-save-per-tick=9',
            '--movement-rewind-history-size=300',
            '--protocol-trace=true',
            '--spawn-x=-16',
            '--spawn-y=70',
            '--spawn-z=32',
            '--log-level=WARNING',
            '--log-console-colors=false',
            '--log-file-history=4',
            '--crash-report-player-identifiers=false',
            '--console-enabled=false',
        ]);
        self::assertSame('Flat development world', $streaming->motd);
        self::assertSame('flatland', $streaming->levelName);
        self::assertSame(-42, $streaming->levelSeed);
        self::assertSame(8, $streaming->viewDistance);
        self::assertSame(6, $streaming->spawnRadius);
        self::assertSame(7, $streaming->chunksSendPerTick);
        self::assertSame(5, $streaming->chunksGeneratePerTick);
        self::assertSame(1_200, $streaming->levelAutosaveIntervalTicks);
        self::assertSame(12, $streaming->chunksSavePerTick);
        self::assertSame(1_400, $streaming->playersAutosaveIntervalTicks);
        self::assertSame(9, $streaming->playersSavePerTick);
        self::assertSame(300, $streaming->movementRewindHistorySize);
        self::assertTrue($streaming->protocolTrace);
        self::assertSame([-16, 70, 32], [$streaming->spawnX, $streaming->spawnY, $streaming->spawnZ]);
        self::assertSame(LogLevel::WARNING, $streaming->loggingLevel);
        self::assertSame('false', $streaming->loggingConsoleColors);
        self::assertSame(4, $streaming->loggingFileHistory);
        self::assertFalse($streaming->crashReportIncludePlayerIdentifiers);
        self::assertFalse($streaming->consoleEnabled);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidArguments(): iterable
    {
        yield 'unknown option' => [['--token=secret']];
        yield 'duplicate option' => [['--port=19132', '--port=19133']];
        yield 'bare option' => [['--port']];
        yield 'hostname bind' => [['--bind=localhost']];
        yield 'zero port' => [['--port=0']];
        yield 'signed integer' => [['--max-players=+2']];
        yield 'excess players' => [['--max-players=1025']];
        yield 'implicit insecure auth' => [['--auth=self_signed']];
        yield 'partial spawn' => [['--spawn-x=0']];
        yield 'noncanonical seed' => [['--seed=-0']];
        yield 'spawn radius exceeds view' => [['--view-distance=2', '--spawn-radius=3']];
        yield 'cache cannot hold all player views' => [['--chunks-cache-limit=1024']];
        yield 'configured views exceed hard cache ceiling' => [[
            '--max-players=16', '--view-distance=32', '--spawn-radius=4', '--chunks-cache-limit=65536',
        ]];
        yield 'unimplemented gamemode' => [['--default-gamemode=creative']];
        yield 'unknown generator' => [['--generator=normal']];
        yield 'wrong-case generator' => [['--generator=DEFAULT']];
        yield 'noncanonical boolean' => [['--protocol-trace=TRUE']];
        yield 'unknown log level' => [['--log-level=TRACE']];
        yield 'invalid console colors' => [['--log-console-colors=yes']];
        yield 'unbounded log file' => [['--log-file-max-size=65535']];
        yield 'excess log history' => [['--log-file-history=101']];
        yield 'autosave interval below one second' => [['--level-autosave-interval-ticks=19']];
        yield 'autosave interval above one hour' => [['--level-autosave-interval-ticks=72001']];
        yield 'zero chunk save budget' => [['--chunks-save-per-tick=0']];
        yield 'excess chunk save budget' => [['--chunks-save-per-tick=65']];
        yield 'player autosave interval below one second' => [['--players-autosave-interval-ticks=19']];
        yield 'player autosave interval above one hour' => [['--players-autosave-interval-ticks=72001']];
        yield 'zero player save budget' => [['--players-save-per-tick=0']];
        yield 'excess player save budget' => [['--players-save-per-tick=65']];
        yield 'zero movement rewind history' => [['--movement-rewind-history-size=0']];
        yield 'excess movement rewind history' => [['--movement-rewind-history-size=1201']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function testRejectsUnboundedAmbiguousAndUnknownInput(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        ServerConfig::fromArguments($arguments);
    }

    public function testSettingsFileIsLoadedBeforeCliOverrides(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bedriox-settings-');
        self::assertIsString($path);
        try {
            file_put_contents($path, "server.name=Configured name\nserver.max-players=8\nlevel.autosave-interval-ticks=8000\nchunks.view-distance=6\nchunks.spawn-radius=5\nchunks.save-per-tick=6\n");
            $config = ServerConfig::fromSettingsFile($path, [
                '--name=CLI name',
                '--view-distance=7',
                '--level-autosave-interval-ticks=9000',
                '--chunks-save-per-tick=7',
            ]);
            self::assertSame('CLI name', $config->serverName);
            self::assertSame(8, $config->maximumPlayers);
            self::assertSame(7, $config->viewDistance);
            self::assertSame(5, $config->spawnRadius);
            self::assertSame(9_000, $config->levelAutosaveIntervalTicks);
            self::assertSame(7, $config->chunksSavePerTick);
        } finally {
            @unlink($path);
        }
    }

    public function testSettingsSpawnMustBeAllEmptyOrAllPopulated(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bedriox-settings-');
        self::assertIsString($path);
        try {
            file_put_contents($path, "level.spawn-x=0\nlevel.spawn-y=\nlevel.spawn-z=0\n");
            $this->expectException(InvalidArgumentException::class);
            ServerConfig::fromSettingsFile($path, []);
        } finally {
            @unlink($path);
        }
    }
}
