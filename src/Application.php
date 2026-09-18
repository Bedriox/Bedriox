<?php

declare(strict_types=1);

namespace Bedriox\Server;

use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Observability\CrashContextProvider;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\CrashHandler;
use Bedriox\Server\Observability\CrashReporter;
use Bedriox\Server\Observability\MutableCrashContextProvider;
use Bedriox\Server\Observability\RotatingFileLog;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Plugin\Event\OwnedEventRegistrar;
use Bedriox\Server\Plugin\PluginComposition;
use Bedriox\Server\Plugin\PluginHost;
use Bedriox\Server\Plugin\PluginManifest;
use Bedriox\Server\Plugin\ServerPluginLogger;
use Bedriox\Server\Runtime\BedrockPlayInitializationFactory;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Runtime\RuntimeLimits;
use Bedriox\Server\Runtime\RuntimeRunner;
use Bedriox\Server\Runtime\ServerBootstrap;
use Bedriox\Server\Runtime\ServerConfig;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\World\SpawnPosition;
use Closure;
use Throwable;

final class Application
{
    public const NAME = 'Bedriox';
    public const VERSION = '0.1.0-alpha.1';

    public function __construct(
        private readonly CrashContextProvider $crashContextProvider = new MutableCrashContextProvider(),
    ) {}

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
        $logger = null;
        try {
            $workingDirectory = getcwd();
            if (!is_string($workingDirectory)) {
                throw new \RuntimeException('Unable to resolve the server working directory.');
            }
            $config = ServerConfig::fromSettingsFile($workingDirectory . DIRECTORY_SEPARATOR . 'bedriox.settings', array_slice($arguments, 1));
            $colors = match ($config->loggingConsoleColors) {
                'true' => true,
                'false' => false,
                default => defined('STDOUT') && function_exists('stream_isatty') && stream_isatty(STDOUT),
            };
            $logger = new ServerLogger(
                $output,
                $config->loggingLevel,
                $config->loggingConsole,
                $colors,
                $config->loggingFile ? new RotatingFileLog(
                    $workingDirectory . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'server.log',
                    $config->loggingFileMaxSize,
                    $config->loggingFileHistory,
                ) : null,
            );
            $crashHandler = new CrashHandler(
                new CrashReporter(
                    $workingDirectory . DIRECTORY_SEPARATOR . 'crashes',
                    $logger,
                    $config->crashReportIncludePlayerIdentifiers,
                    $workingDirectory,
                ),
                $logger,
                fn() => $this->crashContextProvider->current(),
            );
            $crashHandler->install();
            $diagnostics = new RuntimeDiagnostics(static function (string $line) use ($logger): void {
                $logger->debug(trim($line), 'Protocol');
            }, $config->protocolTrace);
            $logger->info('Starting ' . $this->displayName());
            $logger->info(sprintf('Loading world "%s" using %s generator', $config->levelName, $config->levelGenerator));
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
            $composition = new PluginComposition();
            $pluginHost = new PluginHost(
                $workingDirectory . DIRECTORY_SEPARATOR . 'plugins',
                $workingDirectory . DIRECTORY_SEPARATOR . 'plugin_data',
                $logger,
                static function (
                    PluginManifest $manifest,
                    string $dataFolder,
                    OwnedEventRegistrar $events,
                    ServerPluginLogger $pluginLogger,
                ) use ($composition): PluginContext {
                    if ($composition->server === null || $composition->host === null) {
                        throw new \LogicException('Plugin API composition is not ready.');
                    }

                    return new PluginContext(
                        $manifest->name,
                        $pluginLogger,
                        $events,
                        $composition->server->pluginApi->serverFor(
                            $manifest->name,
                            $composition->host->manager(),
                            $composition->host->actions(),
                        ),
                        $dataFolder,
                    );
                },
                maximumPlugins: $config->maximumPlugins,
                crashContext: $this->crashContextProvider instanceof CrashContextPublisher
                    ? $this->crashContextProvider
                    : null,
            );
            $composition->host = $pluginHost;
            $server = (new ServerBootstrap())->create(
                $config,
                $initialization,
                $diagnostics,
                $this->crashContextProvider instanceof CrashContextPublisher ? $this->crashContextProvider : null,
                new PluginGameplayEventBridge($pluginHost->events()),
            );
            $composition->server = $server;
            if ($server->securityWarning !== null) {
                $logger->warning($server->securityWarning);
            }
            $logger->info(sprintf('Listening on %s:%d', $server->localAddress, $server->localPort));
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

            try {
                if ($config->pluginsEnabled) {
                    $pluginHost->start();
                } else {
                    $logger->notice('Plugin loading is disabled', 'Plugins');
                }
                $result = (new RuntimeRunner(
                    $server->runtime,
                    diagnostics: $diagnostics,
                    failureHandler: static function (Throwable $failure) use ($crashHandler): void {
                        $crashHandler->capture($failure);
                    },
                ))->run(static function () use (&$stop): bool {
                    return $stop;
                });
            } finally {
                $pluginHost->stop();
                $server->runtime->close();
            }
            $logger->info($result === 0 ? 'Server stopped cleanly' : 'Server runtime stopped after a failure');

            return $result;
        } catch (Throwable $exception) {
            $diagnostics->record('application.startup_failed', ['exception' => $exception::class]);
            $message = 'Bedriox startup failed closed. Check configuration, required data, authentication discovery, and port availability.';
            if ($logger instanceof ServerLogger) {
                $logger->error($message);
            } else {
                $error($message . PHP_EOL);
            }

            return 1;
        }
    }
}
