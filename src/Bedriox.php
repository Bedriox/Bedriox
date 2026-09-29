<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server;

use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Server\Command\BuiltinCommandRegistrar;
use Bedriox\Server\Command\Default\GarbageCollectionStatus;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Observability\BackgroundLogWriter;
use Bedriox\Server\Observability\CrashContextProvider;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\CrashHandler;
use Bedriox\Server\Observability\CrashReporter;
use Bedriox\Server\Observability\Memory\MemoryPressure;
use Bedriox\Server\Observability\Memory\PhpMemoryUsageProvider;
use Bedriox\Server\Observability\MutableCrashContextProvider;
use Bedriox\Server\Observability\PerformanceMonitor;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Persistence\Player\ProcessPlayerDataStore;
use Bedriox\Server\Persistence\World\ProcessWorldProviderFactory;
use Bedriox\Server\Plugin\Command\ConsoleCommandDriver;
use Bedriox\Server\Plugin\Command\NullConsoleInput;
use Bedriox\Server\Plugin\Command\OwnedCommandRegistrar;
use Bedriox\Server\Plugin\Command\ServerConsoleCommandSender;
use Bedriox\Server\Plugin\Command\StreamConsoleInput;
use Bedriox\Server\Plugin\Command\WindowsConsoleInput;
use Bedriox\Server\Plugin\Event\OwnedEventRegistrar;
use Bedriox\Server\Plugin\OwnedEntityRegistrar;
use Bedriox\Server\Plugin\OwnedGeneratorRegistrar;
use Bedriox\Server\Plugin\OwnedItemRegistrar;
use Bedriox\Server\Plugin\OwnedRecipeRegistrar;
use Bedriox\Server\Plugin\OwnedSourcePluginRegistrar;
use Bedriox\Server\Plugin\PluginComposition;
use Bedriox\Server\Plugin\PluginEntityDefinitionBridge;
use Bedriox\Server\Plugin\PluginEntityLifecycleBridge;
use Bedriox\Server\Plugin\PluginHost;
use Bedriox\Server\Plugin\PluginItemBehaviorRegistrar;
use Bedriox\Server\Plugin\PluginManifest;
use Bedriox\Server\Plugin\PluginRecipeRegistrar;
use Bedriox\Server\Plugin\Scheduler\Worker\ManagedPluginAsyncTaskExecutor;
use Bedriox\Server\Plugin\ServerPluginLogger;
use Bedriox\Server\Runtime\PersistentWorldFactory;
use Bedriox\Server\Runtime\ProcessMemoryLimit;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Runtime\RuntimeRunner;
use Bedriox\Server\Runtime\ServerBootstrap;
use Bedriox\Server\Runtime\ServerConfig;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\World\WorldGeneratorFactory;
use Closure;
use Throwable;

final class Bedriox
{
    public const NAME = 'Bedriox';
    public const VERSION = '0.3.0-alpha.1';

    public function __construct(
        private readonly CrashContextProvider $crashContextProvider = new MutableCrashContextProvider(),
        private readonly ProcessMemoryLimit $processMemoryLimit = new ProcessMemoryLimit(),
    ) {}

    public function displayName(): string
    {
        return BuildInfo::current()->displayName();
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
        $backgroundLog = null;
        $coreWorkers = null;
        $pluginWorkerPool = null;
        $playerStore = null;
        try {
            $workingDirectory = getcwd();
            if (!is_string($workingDirectory)) {
                throw new \RuntimeException('Unable to resolve the server working directory.');
            }
            $config = ServerConfig::fromConfigurationFiles(
                $workingDirectory . DIRECTORY_SEPARATOR . 'server.properties',
                $workingDirectory . DIRECTORY_SEPARATOR . 'bedriox.settings',
                array_slice($arguments, 1),
            );
            $this->processMemoryLimit->apply($config->memoryLimitBytes);
            $colors = match ($config->loggingConsoleColors) {
                'true' => true,
                'false' => false,
                default => defined('STDOUT') && function_exists('stream_isatty') && stream_isatty(STDOUT),
            };
            if ($config->loggingFile) {
                try {
                    $backgroundLog = BackgroundLogWriter::start(
                        $this->displayName(),
                        $workingDirectory . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'server.log',
                        $config->loggingFileMaxSize,
                        $config->loggingFileHistory,
                    );
                } catch (Throwable) {
                    $error('Background file logging is unavailable; continuing with console logging only.' . PHP_EOL);
                }
            }
            $logger = new ServerLogger(
                $output,
                $config->loggingLevel,
                $config->loggingConsole,
                $colors,
                null,
                backgroundFile: $backgroundLog,
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
            $corePool = ManagedWorkerPool::start($this->displayName(), $config->workerCoreCount);
            $coreWorkers = new ManagedWorkerDispatcher($corePool, static function (Throwable $failure) use ($logger): void {
                $logger->error('A core worker completion failed validation (' . $failure::class . ').', 'Workers');
            });
            $pluginAsync = null;
            if ($config->workerCoreCount > 0) {
                $pluginWorkerPool = ManagedWorkerPool::start(
                    $this->displayName(),
                    min(2, max(1, intdiv($config->workerCoreCount, 2))),
                );
                $pluginAsync = new ManagedPluginAsyncTaskExecutor(
                    $pluginWorkerPool,
                    CoreWorkerTaskCatalog::PLUGIN_ASYNC_TASK,
                );
            }
            $logger->info('Starting ' . $this->displayName());
            $logger->info(sprintf('Loading world "%s" using %s generator', $config->levelName, $config->levelGenerator));
            $composition = new PluginComposition();
            $performance = new PerformanceMonitor($config->ticksPerSecond);
            $stop = false;
            $permissionStore = new PermissionStore($workingDirectory . DIRECTORY_SEPARATOR . 'permissions.json');
            $data = \Bedriox\Data\BedrockDataSet::bundled();
            $generatorRegistry = WorldGeneratorFactory::builtIns();
            $itemCatalog = \Bedriox\Server\Gameplay\Item\ItemCatalog::vanilla(
                $data->itemNetworkRegistry(),
                creative: $data->creativeInventoryRegistry(),
                blockItems: $data->blockItemMappingRegistry(),
            );
            $entityDefinitions = EntityDefinitionRegistry::fromData($data->entityTypeRegistry());
            $itemCommandEnum = null;
            $pluginHost = new PluginHost(
                $workingDirectory . DIRECTORY_SEPARATOR . 'plugins',
                $workingDirectory . DIRECTORY_SEPARATOR . 'plugin_data',
                $logger,
                static function (
                    PluginManifest $manifest,
                    string $dataFolder,
                    OwnedEventRegistrar $events,
                    OwnedCommandRegistrar $commands,
                    OwnedSourcePluginRegistrar $sourcePlugins,
                    ServerPluginLogger $pluginLogger,
                ) use ($composition, $itemCatalog, $generatorRegistry, &$itemCommandEnum): PluginContext {
                    if ($composition->server === null || $composition->host === null) {
                        throw new \LogicException('Plugin API composition is not ready.');
                    }
                    if ($composition->itemBehaviors === null) {
                        throw new \LogicException('Plugin item behavior composition is not ready.');
                    }
                    if ($composition->recipes === null) {
                        throw new \LogicException('Plugin crafting recipe composition is not ready.');
                    }

                    return new PluginContext(
                        $manifest->name,
                        $pluginLogger,
                        $events,
                        $commands,
                        $sourcePlugins,
                        $composition->server->pluginApi->serverFor(
                            $manifest->name,
                            $composition->host->manager(),
                        ),
                        $dataFolder,
                        new OwnedItemRegistrar(
                            $manifest->name,
                            $itemCatalog,
                            $itemCommandEnum,
                            $composition->itemBehaviors->register(...),
                        ),
                        recipes: new OwnedRecipeRegistrar($manifest->name, $composition->recipes),
                        entities: new OwnedEntityRegistrar(
                            $manifest->name,
                            $composition->host->entities(),
                            $composition->host->actions(),
                            static fn(
                                \Bedriox\Api\Entity\CustomEntityType $type,
                                \Bedriox\Api\World\Position $position,
                                float $yaw,
                                float $pitch,
                            ): bool => $composition->server->runtime->spawnPluginEntity(
                                $type,
                                $position,
                                $yaw,
                                $pitch,
                            ),
                        ),
                        generators: new OwnedGeneratorRegistrar(
                            $manifest->name,
                            $generatorRegistry,
                            $composition->host->ownership(),
                        ),
                        containers: $composition->server->pluginApi->containerManagerFor(
                            $manifest->name,
                            $composition->host->manager(),
                            $composition->host->actions(),
                            $composition->host->ownership(),
                        ),
                    );
                },
                maximumPlugins: $config->maximumPlugins,
                crashContext: $this->crashContextProvider instanceof CrashContextPublisher
                    ? $this->crashContextProvider
                    : null,
                asyncTaskExecutor: $pluginAsync,
                onlinePlayers: static fn(): array => $composition->server?->runtime->onlinePlayers() ?? [],
                commandEntities: static fn(): array => $composition->server?->runtime->entities() ?? [],
                commandSelectorOrigin: static function () use ($composition): ?\Bedriox\Api\World\Position {
                    $world = $composition->server?->world;
                    if ($world === null) {
                        return null;
                    }
                    $spawn = $world->spawn();

                    return new \Bedriox\Api\World\Position($spawn->x + 0.5, $spawn->y, $spawn->z + 0.5);
                },
            );
            $composition->host = $pluginHost;
            $definitionBridge = new PluginEntityDefinitionBridge($entityDefinitions, $pluginHost->entities());
            $pluginHost->entities()->bindDefinitionBridge($definitionBridge);
            $composition->entityLifecycle = new PluginEntityLifecycleBridge($pluginHost->entities());
            $playerStore = ProcessPlayerDataStore::start(
                self::VERSION,
                $workingDirectory . DIRECTORY_SEPARATOR . 'player_data',
            );
            $itemCommandEnum = (new BuiltinCommandRegistrar(
                $pluginHost->commands(),
                $permissionStore,
                static fn(): array => $composition->server?->runtime->onlinePlayers() ?? [],
                static function () use (&$stop): void {
                    $stop = true;
                },
                $itemCatalog->commandIdentifiers(...),
                static function (\Bedriox\Api\Player\Player $player, bool $includeAbilities) use ($composition): void {
                    $composition->server?->runtime->refreshPlayerAuthority($player->uuid, $includeAbilities);
                },
                static fn(\Bedriox\Api\Player\Player $player, \Bedriox\Api\Player\GameMode $gameMode): bool =>
                    $composition->server?->runtime->changePlayerGameMode($player->uuid, $gameMode) ?? false,
                static fn(\Bedriox\Api\Player\Player $player, string $identifier, int $amount): bool =>
                    $composition->server?->runtime->givePlayerItem($player->uuid, $identifier, $amount) ?? false,
                static fn(string $identifier): bool => $itemCatalog->has($identifier),
                static function () use ($composition, $performance, $config, $coreWorkers, $pluginWorkerPool, $backgroundLog, $pluginHost, $playerStore): \Bedriox\Server\Observability\PerformanceSnapshot {
                    $server = $composition->server;
                    $runtime = $server?->runtime;
                    $world = $server?->world;
                    $scheduler = $pluginHost->scheduler();

                    return $performance->snapshot(
                        onlinePlayers: $runtime?->sessionCount() ?? 0,
                        maximumPlayers: $config->maximumPlayers,
                        loadedChunks: $runtime?->loadedChunkCount() ?? 0,
                        dirtyChunks: $runtime?->dirtyChunkCount() ?? 0,
                        generatingChunks: $runtime?->generatingChunkCount() ?? 0,
                        scheduledPluginTasks: $scheduler->scheduledCount(),
                        deferredPluginTasks: $scheduler->deferredLastTick(),
                        coreWorkers: $coreWorkers->snapshot(),
                        pluginWorkers: $pluginWorkerPool?->snapshot(),
                        logWriter: $backgroundLog?->snapshot(),
                        configuredMemoryLimitBytes: $config->memoryLimitBytes,
                        worldCount: $runtime?->worldCount() ?? 0,
                        entityCount: $runtime?->entityCount() ?? 0,
                        pendingAsyncPluginTasks: $scheduler->pendingAsyncCount(),
                        maximumAsyncCompletionsPerTick: $scheduler->maximumAsyncCompletionsPerTick(),
                        chunkCache: $runtime?->chunkRepositorySnapshot(),
                        chunkStreaming: $runtime?->chunkStreamingSnapshot(),
                        worldPersistence: $runtime?->worldPersistenceQueueSnapshot(),
                        playerPersistence: $playerStore->persistenceQueueSnapshot(),
                        preparedChunkCache: $runtime?->preparedChunkCacheSnapshot(),
                        memoryManagement: $runtime?->lastMemoryManagementDecision(),
                        garbageCollection: $runtime?->lastGarbageCollectionReport(),
                        garbageCollectorRuns: $runtime?->garbageCollectorRuns() ?? 0,
                        garbageCollectorThreshold: $runtime?->garbageCollectorThreshold() ?? 0,
                        chunkUnload: $runtime?->lastChunkUnloadResult(),
                        totalChunksUnloaded: $runtime?->totalChunksUnloaded() ?? 0,
                        preparedBytesTrimmed: $runtime?->totalPreparedBytesTrimmed() ?? 0,
                        entityAi: $runtime?->entityAiMetrics(),
                        entityRuntime: $runtime?->entityRuntimeMetrics(),
                    );
                },
                static fn(
                    \Bedriox\Api\Player\Player $player,
                    \Bedriox\Api\World\Position $position,
                    ?float $yaw,
                    ?float $pitch,
                ): bool => $composition->server?->runtime->teleportPlayer(
                    $player->uuid,
                    $position,
                    $yaw,
                    $pitch,
                ) ?? false,
                garbageCollectionStatus: static function () use ($composition, $config): GarbageCollectionStatus {
                    $runtime = $composition->server?->runtime;
                    $repository = $composition->server?->world->chunkRepositorySnapshot();
                    if ($runtime === null || $repository === null) {
                        throw new \LogicException('Garbage collection runtime is unavailable.');
                    }
                    $decision = $runtime->lastMemoryManagementDecision();
                    $memory = $decision === null
                        ? (new PhpMemoryUsageProvider($config->memoryLimitBytes))->snapshot()
                        : $decision->snapshot;
                    $pressure = $decision === null ? MemoryPressure::NORMAL : $decision->pressure;

                    return new GarbageCollectionStatus(
                        $memory,
                        $pressure,
                        $runtime->garbageCollectorRuns(),
                        $runtime->garbageCollectorThreshold(),
                        $runtime->lastGarbageCollectionReport(),
                        $repository->loaded,
                        $repository->retainedChunks,
                        $repository->dirty,
                        $runtime->pendingChunkUnloadCount(),
                        $runtime->totalChunksUnloaded(),
                    );
                },
                collectGarbage: static function () use ($composition): \Bedriox\Server\Observability\Memory\GarbageCollectionReport {
                    return $composition->server?->runtime->forceGarbageCollection()
                        ?? throw new \LogicException('Garbage collection runtime is unavailable.');
                },
                unloadChunks: static function () use ($composition): \Bedriox\Server\World\ChunkUnloadResult {
                    return $composition->server?->runtime->runChunkUnloadMaintenance()
                        ?? throw new \LogicException('Chunk unload runtime is unavailable.');
                },
                entityIdentifiers: static fn(): array => array_values(array_map(
                    static fn(\Bedriox\Data\EntityTypeDefinition $definition): string => $definition->identifier(),
                    array_filter(
                        $data->entityTypeRegistry()->definitions(),
                        static fn(\Bedriox\Data\EntityTypeDefinition $definition): bool => $definition->summonable(),
                    ),
                )),
                summonEntity: static fn(
                    string $identifier,
                    \Bedriox\Api\World\Position $position,
                    ?\Bedriox\Api\Player\Player $source,
                ): bool => $composition->server?->runtime->summonEntity($identifier, $position, $source) ?? false,
                currentWorldTime: static fn(): ?int => $composition->server?->runtime->currentWorldTime(),
                setWorldTime: static fn(int $time): ?int => $composition->server?->runtime->setWorldTime($time),
                addWorldTime: static fn(int $amount): ?int => $composition->server?->runtime->addWorldTime($amount),
                setWorldTimeRunning: static fn(bool $running): ?int =>
                    $composition->server?->runtime->setWorldTimeRunning($running),
                killTarget: static fn(\Bedriox\Api\Player\Player|\Bedriox\Api\Entity\Entity $target): bool =>
                    $composition->server?->runtime->killTarget($target) ?? false,
                currentWeather: static fn(?\Bedriox\Api\World\World $world): ?\Bedriox\Api\World\WeatherState =>
                    $composition->server?->runtime->currentWeather($world),
                setWeather: static fn(
                    ?\Bedriox\Api\World\World $world,
                    \Bedriox\Api\World\WeatherType $type,
                    ?int $durationSeconds,
                ): ?\Bedriox\Api\World\WeatherState =>
                    $composition->server?->runtime->setWeather($world, $type, $durationSeconds),
            ))->register();
            $server = (new ServerBootstrap(
                new PersistentWorldFactory(
                    $workingDirectory,
                    new ProcessWorldProviderFactory(self::VERSION),
                    $generatorRegistry,
                ),
                playerDataDirectory: $workingDirectory . DIRECTORY_SEPARATOR . 'player_data',
                playerDataStore: $playerStore,
            ))->create(
                $config,
                null,
                $diagnostics,
                $this->crashContextProvider instanceof CrashContextPublisher ? $this->crashContextProvider : null,
                new PluginGameplayEventBridge($pluginHost->events()),
                $pluginHost->commands(),
                $permissionStore,
                $itemCatalog,
                $performance,
                $pluginHost->tickScheduler(...),
                $coreWorkers,
                entityDefinitions: $entityDefinitions,
                pluginEntityLifecycle: $composition->entityLifecycle,
                pluginActions: $pluginHost->actions(),
            );
            $composition->server = $server;
            $composition->itemBehaviors = new PluginItemBehaviorRegistrar(
                $server->pluginApi->itemBehaviorRegistry(),
                $pluginHost->ownership(),
                $itemCatalog,
            );
            $composition->recipes = new PluginRecipeRegistrar(
                $server->craftingCatalog,
                $pluginHost->ownership(),
                $itemCatalog,
                $server->pluginApi->blockStateRegistry(),
            );
            $logger->info(sprintf(
                'Loaded world "%s" using %s generator',
                $server->world->metadata->name,
                $server->world->generatorName(),
            ));
            if ($server->securityWarning !== null) {
                $logger->warning($server->securityWarning);
            }
            $logger->info(sprintf('Listening on %s:%d', $server->localAddress, $server->localPort));
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
                $consoleInput = new NullConsoleInput();
                if ($config->consoleEnabled && defined('STDIN') && is_resource(STDIN)) {
                    if (PHP_OS_FAMILY === 'Windows') {
                        try {
                            $consoleInput = WindowsConsoleInput::start(STDIN, $logger);
                        } catch (Throwable $failure) {
                            $logger->warning(sprintf(
                                'Windows console input could not start (%s); console commands are disabled',
                                $failure::class,
                            ), 'Command');
                        }
                    } else {
                        $consoleInput = new StreamConsoleInput(STDIN, $logger);
                    }
                }
                $runtimeDriver = new ConsoleCommandDriver(
                    $server->runtime,
                    $consoleInput,
                    $pluginHost->commands(),
                    new ServerConsoleCommandSender($logger),
                    $logger,
                );
                $result = (new RuntimeRunner(
                    $runtimeDriver,
                    diagnostics: $diagnostics,
                    failureHandler: static function (Throwable $failure) use ($crashHandler): void {
                        $crashHandler->capture($failure);
                    },
                    performance: $performance,
                    backgroundPoll: static function () use ($coreWorkers, $backgroundLog): void {
                        $coreWorkers->pollWithinBudget(256, 5_000_000);
                        $backgroundLog?->poll();
                    },
                ))->run(static function () use (&$stop): bool {
                    return $stop;
                });
            } finally {
                $server->runtime->close();
                $pluginHost->stop();
            }
            $logger->info($result === 0 ? 'Server stopped cleanly' : 'Server runtime stopped after a failure');
            if ($backgroundLog !== null) {
                if (!$backgroundLog->shutdown()) {
                    $error('Server shutdown could not confirm background log durability.' . PHP_EOL);
                    $result = 1;
                }
                $backgroundLog = null;
            }

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
        } finally {
            $pluginWorkerPool?->shutdown();
            $coreWorkers?->shutdown();
            try {
                $playerStore?->close();
            } catch (Throwable) {
                $error('Player storage shutdown could not be confirmed.' . PHP_EOL);
            }
            $backgroundLog?->shutdown();
        }
    }
}
