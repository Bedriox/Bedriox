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
        self::assertSame('survival', $defaults->defaultGamemode);
        self::assertSame(4, $defaults->chunksGeneratePerTick);
        self::assertSame(8, $defaults->chunksSendPerTick);
        self::assertSame(500_000_000, $defaults->memoryLimitBytes);
        self::assertSame(1_024, $defaults->chunkGenerationQueueSize);
        self::assertSame(1, $defaults->chunkLoadingPrefetchRadius);
        self::assertSame(2_425, $defaults->chunkCacheLimit);
        self::assertSame(600, $defaults->chunkUnloadGraceTicks);
        self::assertSame(96, $defaults->chunkUnloadPerTick);
        self::assertTrue($defaults->memoryManagementEnabled);
        self::assertSame(70, $defaults->memorySoftThreshold);
        self::assertSame(85, $defaults->memoryHighThreshold);
        self::assertSame(92, $defaults->memoryCriticalThreshold);
        self::assertTrue($defaults->pvp);

        $config = ServerConfig::fromArguments([
            '--bind=127.0.0.1',
            '--port=19133',
            '--name=Private Test',
            '--max-players=12',
            '--auth=SELF_SIGNED',
            '--default-gamemode=creative',
        ]);
        self::assertSame('127.0.0.1', $config->bindAddress);
        self::assertSame(19_133, $config->port);
        self::assertSame('Private Test', $config->serverName);
        self::assertSame(12, $config->maximumPlayers);
        self::assertSame(AuthenticationMode::SELF_SIGNED, $config->authenticationMode);
        self::assertSame('creative', $config->defaultGamemode);

        $streaming = ServerConfig::fromArguments([
            '--motd=Flat development world',
            '--level-name=flatland',
            '--seed=-42',
            '--view-distance=8',
            '--spawn-radius=6',
            '--chunks-send-per-tick=7',
            '--chunks-generate-per-tick=5',
            '--chunks-cache-limit=8000',
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
            '--pvp=false',
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
        self::assertFalse($streaming->pvp);
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
        yield 'invalid pvp boolean' => [['--pvp=1']];
        yield 'partial spawn' => [['--spawn-x=0']];
        yield 'noncanonical seed' => [['--seed=-0']];
        yield 'spawn radius exceeds view' => [['--view-distance=2', '--spawn-radius=3']];
        yield 'cache cannot hold all player views' => [['--chunks-cache-limit=1024']];
        yield 'cache cannot hold hidden prefetched views' => [['--chunks-cache-limit=2419']];
        yield 'configured views exceed hard cache ceiling' => [[
            '--max-players=16', '--view-distance=32', '--spawn-radius=4', '--chunks-cache-limit=65536',
        ]];
        yield 'unknown gamemode' => [['--default-gamemode=builder']];
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
        yield 'malformed memory limit' => [['--memory-limit=500M']];
        yield 'memory limit below minimum' => [['--memory-limit=127MB']];
        yield 'excess generation queue' => [['--chunk-generation-queue-size=65537']];
        yield 'excess prefetch radius' => [['--chunk-loading-prefetch-radius=9']];
        yield 'visible and prefetched radius exceeds ceiling' => [[
            '--view-distance=29', '--chunk-loading-prefetch-radius=4', '--chunks-cache-limit=65536',
        ]];
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
        $properties = tempnam(sys_get_temp_dir(), 'bedriox-properties-');
        $settings = tempnam(sys_get_temp_dir(), 'bedriox-settings-');
        self::assertIsString($properties);
        self::assertIsString($settings);
        try {
            file_put_contents($properties, "server-name=Configured name\nmax-players=8\nmemory-limit=1GiB\nview-distance=6\n");
            file_put_contents($settings, "level.autosave-interval-ticks=8000\nchunk-sending.spawn-radius=5\nchunk-saving.per-tick=6\nchunk-generation.queue-size=2048\nchunk-loading.prefetch-radius=2\nchunk-unloading.grace-ticks=400\nchunk-unloading.per-tick=72\nmemory-management.enabled=false\nmemory-management.soft-threshold=65\nmemory-management.high-threshold=80\nmemory-management.critical-threshold=90\n");
            $config = ServerConfig::fromConfigurationFiles($properties, $settings, [
                '--name=CLI name',
                '--view-distance=7',
                '--level-autosave-interval-ticks=9000',
                '--chunks-save-per-tick=7',
                '--memory-limit=2GB',
            ]);
            self::assertSame('CLI name', $config->serverName);
            self::assertSame(8, $config->maximumPlayers);
            self::assertSame(7, $config->viewDistance);
            self::assertSame(5, $config->spawnRadius);
            self::assertSame(9_000, $config->levelAutosaveIntervalTicks);
            self::assertSame(7, $config->chunksSavePerTick);
            self::assertSame(2_000_000_000, $config->memoryLimitBytes);
            self::assertSame(2_048, $config->chunkGenerationQueueSize);
            self::assertSame(2, $config->chunkLoadingPrefetchRadius);
            self::assertSame(400, $config->chunkUnloadGraceTicks);
            self::assertSame(72, $config->chunkUnloadPerTick);
            self::assertFalse($config->memoryManagementEnabled);
            self::assertSame(65, $config->memorySoftThreshold);
            self::assertSame(80, $config->memoryHighThreshold);
            self::assertSame(90, $config->memoryCriticalThreshold);
        } finally {
            @unlink($properties);
            @unlink($settings);
        }
    }

    public function testMemoryThresholdsMustBeStrictlyIncreasing(): void
    {
        $properties = tempnam(sys_get_temp_dir(), 'bedriox-properties-');
        $settings = tempnam(sys_get_temp_dir(), 'bedriox-settings-');
        self::assertIsString($properties);
        self::assertIsString($settings);
        try {
            file_put_contents($properties, '');
            file_put_contents($settings, "memory-management.soft-threshold=85\nmemory-management.high-threshold=85\n");
            $this->expectException(InvalidArgumentException::class);
            ServerConfig::fromConfigurationFiles($properties, $settings, []);
        } finally {
            @unlink($properties);
            @unlink($settings);
        }
    }

    public function testSettingsSpawnMustBeAllEmptyOrAllPopulated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ServerConfig::fromArguments(['--spawn-x=0', '--spawn-y=', '--spawn-z=0']);
    }

    public function testFilesUseIndependentAllowlistsWithoutMigratingLegacyKeys(): void
    {
        $properties = tempnam(sys_get_temp_dir(), 'bedriox-properties-');
        $settings = tempnam(sys_get_temp_dir(), 'bedriox-settings-');
        self::assertIsString($properties);
        self::assertIsString($settings);
        try {
            file_put_contents($properties, "runtime.ticks-per-second=20\n");
            file_put_contents($settings, '');
            try {
                ServerConfig::fromConfigurationFiles($properties, $settings, []);
                self::fail('Advanced setting was accepted in server.properties.');
            } catch (InvalidArgumentException) {
            }

            file_put_contents($properties, "server-name=Configured\n");
            file_put_contents($settings, "server.name=Legacy\n");
            $this->expectException(InvalidArgumentException::class);
            ServerConfig::fromConfigurationFiles($properties, $settings, []);
        } finally {
            @unlink($properties);
            @unlink($settings);
        }
    }

    public function testCliAuthenticationOverridesServerProperties(): void
    {
        $properties = tempnam(sys_get_temp_dir(), 'bedriox-properties-');
        $settings = tempnam(sys_get_temp_dir(), 'bedriox-settings-');
        self::assertIsString($properties);
        self::assertIsString($settings);
        try {
            file_put_contents($properties, "xbox-auth=false\n");
            file_put_contents($settings, '');

            $config = ServerConfig::fromConfigurationFiles($properties, $settings, ['--auth=FULL']);

            self::assertSame(AuthenticationMode::FULL, $config->authenticationMode);
        } finally {
            @unlink($properties);
            @unlink($settings);
        }
    }
}
