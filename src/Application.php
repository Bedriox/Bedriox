<?php

declare(strict_types=1);

namespace Bedriox\Server;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Runtime\BedrockPlayInitializationFactory;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Runtime\RuntimeLimits;
use Bedriox\Server\Runtime\RuntimeRunner;
use Bedriox\Server\Runtime\ServerBootstrap;
use Bedriox\Server\Runtime\ServerConfig;
use Bedriox\Server\World\SpawnPosition;
use Closure;
use Throwable;

final class Application
{
    public const NAME = 'Bedriox';
    public const VERSION = '0.1.0-alpha.1';

    public function displayName(): string
    {
        return sprintf('%s %s', self::NAME, self::VERSION);
    }

    /**
     * @param list<string> $arguments
     * @param Closure(string): void $output
     * @param Closure(string): void $error
     */
    public function run(array $arguments, Closure $output, Closure $error): int
    {
        if ($arguments === ['--version']) {
            $output($this->displayName() . PHP_EOL);

            return 0;
        }
        if (($arguments[0] ?? null) !== 'serve') {
            $error('Bedriox is in pre-alpha development. Use --version or serve with explicit options.' . PHP_EOL);

            return 1;
        }
        $diagnostics = RuntimeDiagnostics::disabled();
        try {
            $workingDirectory = getcwd();
            if (!is_string($workingDirectory)) {
                throw new \RuntimeException('Unable to resolve the server working directory.');
            }
            $config = ServerConfig::fromSettingsFile($workingDirectory . DIRECTORY_SEPARATOR . 'bedriox.settings', array_slice($arguments, 1));
            $diagnostics = new RuntimeDiagnostics($error, $config->protocolTrace);
            $spawn = new SpawnPosition(
                $config->spawnX ?? 0,
                $config->spawnY ?? 64,
                $config->spawnZ ?? 0,
            );
            $initialization = new BedrockPlayInitializationFactory(
                BedrockDataSet::bundled(),
                new RuntimeLimits(
                    maximumSessions: $config->maximumPlayers,
                    maximumChunkRadius: $config->viewDistance,
                    preloadedChunkRadius: $config->spawnRadius,
                    maximumStreamingPacketsPerPoll: $config->chunksSendPerTick,
                ),
                $config->levelName,
                $spawn,
                match ($config->difficulty) {
                    'peaceful' => 0,
                    'easy' => 1,
                    'normal' => 2,
                    'hard' => 3,
                },
                $config->levelSeed,
            );
            $server = (new ServerBootstrap())->create($config, $initialization, $diagnostics);
            if ($server->securityWarning !== null) {
                $error($server->securityWarning . PHP_EOL);
            }
            $output(sprintf('Bedriox listening on %s:%d.%s', $server->localAddress, $server->localPort, PHP_EOL));
            $stop = false;
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                foreach (['SIGINT', 'SIGTERM'] as $name) {
                    $signal = defined($name) ? constant($name) : null;
                    if (is_int($signal)) {
                        pcntl_signal($signal, static function () use (&$stop): void {
                            $stop = true;
                        });
                    }
                }
            }

            return (new RuntimeRunner($server->runtime, diagnostics: $diagnostics))->run(static function () use (&$stop): bool {
                return $stop;
            });
        } catch (Throwable $exception) {
            $diagnostics->record('application.startup_failed', ['exception' => $exception::class]);
            $error('Bedriox startup failed closed. Check configuration, required data, authentication discovery, and port availability.' . PHP_EOL);

            return 1;
        }
    }
}
