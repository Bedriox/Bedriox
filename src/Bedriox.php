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

use Bedriox\Api\Plugin\Data\PluginData;
use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Server\Access\BanManager;
use Bedriox\Server\Access\WhitelistManager;
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
use Bedriox\Server\Observability\PlayerLifecycleLogger;
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
use Bedriox\Server\Runtime\PortUnavailableException;
use Bedriox\Server\Runtime\ProcessMemoryLimit;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Runtime\RuntimeRunner;
use Bedriox\Server\Runtime\ServerBootstrap;
use Bedriox\Server\Runtime\ServerConfig;
use Bedriox\Server\Runtime\SetupWizard;
use Bedriox\Server\Runtime\UdpBindPreflight;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Update\UpdateManager;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\World\WorldGeneratorFactory;
use Closure;
use Throwable;

final class Bedriox
{
    public const NAME = 'Bedriox';
    public const VERSION = '1.0.0-beta.3-dev';

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
        $output($this->banner() . PHP_EOL);
        if ($arguments !== [] && $arguments[0] === 'serve') {
            array_shift($arguments);
        }
        $skipWizard = false;
        if (($index = array_search('--skip-wizard', $arguments, true)) !== false) {
            $skipWizard = true;
            array_splice($arguments, $index, 1);
        }
        if (array_filter($arguments, static fn(string $argument): bool => !str_starts_with($argument, '--') || !str_contains($argument, '=')) !== []) {
            $error('Unknown invocation. Start Bedriox without arguments, use serve with explicit options, or use --version.' . PHP_EOL);

            return 1;
        }
        $configurationArguments = $arguments;
        $diagnostics = RuntimeDiagnostics::disabled();
        $logger = null;
        $backgroundLog = null;
        $coreWorkers = null;
        $pluginWorkerPool = null;
        $playerStore = null;
        $updates = null;
        try {
            $workingDirectory = getcwd();
            if (!is_string($workingDirectory)) {
                throw new \RuntimeException('Unable to resolve the server working directory.');
            }
            $propertiesPath = $workingDirectory . DIRECTORY_SEPARATOR . 'server.properties';
            $wizardCompleted = false;
            if (!file_exists($propertiesPath)) {
                if ($skipWizard) {
                    SetupWizard::installDefaults($propertiesPath);
                } else {
                    if (!defined('STDIN') || !is_resource(STDIN)
                        || (function_exists('stream_isatty') && !stream_isatty(STDIN))) {
                        throw new \RuntimeException('First-run setup requires an interactive terminal. Use --skip-wizard to install defaults.');
                    }
                    (new SetupWizard(
                        $output,
                        static function (): ?string {
                            $line = fgets(STDIN);
                            return is_string($line) ? $line : null;
                        },
                    ))->run($propertiesPath, $workingDirectory . DIRECTORY_SEPARATOR . 'whitelist.json');
                    $wizardCompleted = true;
                }
            }
            if (!$wizardCompleted) {
                $output('Preparing Bedriox, please wait...' . PHP_EOL);
            }
            $config = ServerConfig::fromConfigurationFiles(
                $propertiesPath,
                $workingDirectory . DIRECTORY_SEPARATOR . 'bedriox.settings',
                $configurationArguments,
            );
            $this->processMemoryLimit->apply($config->memoryLimitBytes);
            (new UdpBindPreflight())->assertAvailable($config->bindAddress, $config->port);
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
            $startupStartedAt = hrtime(true);
            $logger->info('Starting ' . $this->displayName());
            $logger->info(sprintf('Loading world "%s" using %s generator', $config->levelName, $config->levelGenerator));
            $logger->info('Loading server data and world metadata');
            $composition = new PluginComposition();
            $performance = new PerformanceMonitor($config->ticksPerSecond);
            $stop = false;
            $permissionStore = new PermissionStore($workingDirectory . DIRECTORY_SEPARATOR . 'permissions.json');
            $data = \Bedriox\Data\BedrockDataSet::bundled();
            $generatorRegistry = WorldGeneratorFactory::builtIns();
            $worldFactory = new PersistentWorldFactory(
                $workingDirectory,
                new ProcessWorldProviderFactory(self::VERSION),
                $generatorRegistry,
            );
            $internalStates = $worldFactory->internalStates($data);
            $blockCatalog = \Bedriox\Server\Gameplay\Block\BlockCatalog::vanilla(
                $internalStates,
                $data->blockItemMappingRegistry(),
            );
            $itemCatalog = \Bedriox\Server\Gameplay\Item\ItemCatalog::vanilla(
                $data->itemNetworkRegistry(),
                $blockCatalog,
                creative: $data->creativeInventoryRegistry(),
                blockItems: $data->blockItemMappingRegistry(),
            );
            $entityDefinitions = EntityDefinitionRegistry::fromData($data->entityTypeRegistry());
            $startupCatalogsReadyAt = hrtime(true);
            $itemCommandEnum = null;
            $pluginHost = new PluginHost(
                $workingDirectory . DIRECTORY_SEPARATOR . 'plugins',
                $workingDirectory . DIRECTORY_SEPARATOR . 'plugin_data',
                $logger,
                static function (
                    PluginManifest $manifest,
                    PluginData $data,
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
                            $composition->host->actions(),
                        ),
                        $data,
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
                        bossBars: $composition->host->bossBars($manifest->name),
                        encounters: $composition->server->pluginApi->encounterManagerFor(
                            $manifest->name,
                            $composition->host->manager(),
                        ),
                        updates: $composition->updates
                            ?? throw new \LogicException('The update capability is unavailable.'),
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
                administrativeCommandFeedback: static function (
                    \Bedriox\Api\Command\CommandSender $source,
                    string $message,
                ) use ($composition, $permissionStore, $logger): void {
                    $formatted = '[' . $source->name() . ': ' . $message . ']';
                    if ($source->type() !== \Bedriox\Api\Command\CommandSenderType::CONSOLE) {
                        $logger->info($formatted, 'Command');
                    }
                    $sourceUuid = $source instanceof \Bedriox\Api\Command\PlayerCommandSender
                        ? $source->player()->uuid
                        : null;
                    foreach ($composition->server?->runtime->onlinePlayers() ?? [] as $player) {
                        if ($player->uuid === $sourceUuid
                            || !$permissionStore->hasPermission($player->uuid, 'bedriox.command.broadcast.admin')) {
                            continue;
                        }
                        $player->sendMessage(
                            \Bedriox\Api\TextFormat::GRAY
                            . \Bedriox\Api\TextFormat::ITALIC
                            . $formatted
                            . \Bedriox\Api\TextFormat::RESET,
                        );
                    }
                },
            );
            $composition->host = $pluginHost;
            $definitionBridge = new PluginEntityDefinitionBridge($entityDefinitions, $pluginHost->entities());
            $pluginHost->entities()->bindDefinitionBridge($definitionBridge);
            $composition->entityLifecycle = new PluginEntityLifecycleBridge($pluginHost->entities());
            $pluginEvents = new PluginGameplayEventBridge($pluginHost->events());
            $whitelist = new WhitelistManager(
                $workingDirectory . DIRECTORY_SEPARATOR . 'whitelist.json',
                $config->whitelistEnabled,
                static function (bool $enabled) use ($workingDirectory): void {
                    self::updateServerProperty(
                        $workingDirectory . DIRECTORY_SEPARATOR . 'server.properties',
                        'white-list',
                        $enabled ? 'true' : 'false',
                    );
                },
                $pluginEvents->dispatch(...),
            );
            $bans = new BanManager(
                $workingDirectory . DIRECTORY_SEPARATOR . 'bans.json',
                $pluginEvents->dispatch(...),
            );
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
                        entityNavigation: $runtime?->entityNavigationMetrics(),
                        transportSecurity: $runtime?->transportSecuritySnapshot(),
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
                whitelist: $whitelist,
                enforceWhitelist: static function () use ($composition): void {
                    $composition->server?->runtime->enforceWhitelist();
                },
                broadcast: static function (string $message) use ($composition): int {
                    $players = $composition->server?->runtime->onlinePlayers() ?? [];
                    foreach ($players as $player) {
                        $player->sendMessage($message);
                    }
                    return count($players);
                },
                pluginStates: static fn(): array => $pluginHost->manager()->pluginStates(),
                worldSeed: static function (?\Bedriox\Api\World\World $world) use ($composition): ?int {
                    $manager = $composition->server?->worldManager;
                    if ($manager === null) {
                        return null;
                    }
                    $handle = $world ?? $manager->getDefault();

                    return $manager->info($handle)->seed;
                },
                setBlock: static function (
                    \Bedriox\Api\World\BlockPosition $position,
                    string $identifier,
                    ?\Bedriox\Api\World\World $world,
                ) use ($composition): bool {
                    $manager = $composition->server?->worldManager;
                    $handle = $manager === null ? null : ($world ?? $manager->getDefault());
                    if ($handle === null) {
                        return false;
                    }
                    try {
                        $handle->setBlock($position, $identifier);
                        return true;
                    } catch (\Throwable) {
                        return false;
                    }
                },
                enchant: static function (\Bedriox\Api\Player\Player $player, string $identifier, int $level) use ($itemCatalog): bool {
                    $inventory = $player->getInventory();
                    $slot = $inventory->getSelectedHotbarSlot();
                    $stack = $inventory->getHeldItem();
                    $registry = \Bedriox\Server\Gameplay\Enchanting\VanillaEnchantmentRegistry::create();
                    $definition = $registry->find($identifier);
                    if ($stack === null || $definition === null || $level > $definition->maximumLevel
                        || !\Bedriox\Server\Gameplay\Enchanting\EnchantmentApplicability::accepts(
                            $definition,
                            $itemCatalog->type($stack->identifier),
                        )) {
                        return false;
                    }
                    $enchantments = \Bedriox\Server\Gameplay\Processing\WorkstationItemData::enchantments($stack->nbt);
                    foreach (array_keys($enchantments) as $existing) {
                        $existingDefinition = $registry->find($existing);
                        if ($definition->conflictsWith($existing) || $existingDefinition?->conflictsWith($identifier) === true) {
                            return false;
                        }
                    }
                    $enchantments[$identifier] = $level;
                    $inventory->setItem($slot, new \Bedriox\Api\Inventory\ItemStack(
                        $stack->identifier,
                        $stack->count,
                        $stack->damage,
                        \Bedriox\Server\Gameplay\Processing\WorkstationItemData::withEnchantments($stack->nbt, $enchantments),
                        $stack->auxValue,
                    ));
                    return true;
                },
                saveWorlds: static function () use ($composition): int {
                    $manager = $composition->server?->worldManager;
                    $worlds = $manager?->getLoaded() ?? [];
                    foreach ($worlds as $world) {
                        $manager->save($world);
                    }
                    return count($worlds);
                },
                currentDefaultGameMode: static fn(): \Bedriox\Api\Player\GameMode =>
                    $composition->server?->runtime->defaultGameMode() ?? \Bedriox\Api\Player\GameMode::SURVIVAL,
                setDefaultGameMode: static function (\Bedriox\Api\Player\GameMode $mode) use ($composition, $workingDirectory, $pluginEvents): bool {
                    $runtime = $composition->server?->runtime;
                    if ($runtime === null) {
                        return false;
                    }
                    $previous = $runtime->defaultGameMode();
                    $event = new \Bedriox\Api\Event\Server\DefaultGameModeChangeEvent($previous, $mode);
                    $pluginEvents->dispatch($event);
                    if ($event->isCancelled()) {
                        return false;
                    }
                    self::updateServerProperty(
                        $workingDirectory . DIRECTORY_SEPARATOR . 'server.properties',
                        'gamemode',
                        $mode->value,
                    );
                    if (!$runtime->setDefaultGameMode($mode)) {
                        return false;
                    }
                    $pluginEvents->dispatch(new \Bedriox\Api\Event\Server\DefaultGameModeChangedEvent($previous, $mode));

                    return true;
                },
                currentDifficulty: static fn(?\Bedriox\Api\World\World $world): ?\Bedriox\Api\World\WorldDifficulty =>
                    $composition->server?->runtime->worldDifficulty($world),
                setDifficulty: static function (
                    ?\Bedriox\Api\World\World $world,
                    \Bedriox\Api\World\WorldDifficulty $difficulty,
                ) use ($composition, $pluginEvents): bool {
                    $runtime = $composition->server?->runtime;
                    $world ??= $composition->server?->worldManager->getDefault();
                    $previous = $runtime?->worldDifficulty($world);
                    if ($runtime === null || $world === null || $previous === null) {
                        return false;
                    }
                    $event = new \Bedriox\Api\Event\World\WorldDifficultyChangeEvent($world, $previous, $difficulty);
                    $pluginEvents->dispatch($event);
                    if ($event->isCancelled() || !$runtime->setWorldDifficulty($world, $difficulty)) {
                        return false;
                    }
                    $pluginEvents->dispatch(new \Bedriox\Api\Event\World\WorldDifficultyChangedEvent($world, $previous, $difficulty));
                    return true;
                },
                setWorldSpawn: static function (
                    \Bedriox\Api\World\World $world,
                    \Bedriox\Api\World\BlockPosition $position,
                ) use ($composition, $pluginEvents): bool {
                    $manager = $composition->server?->worldManager;
                    $runtime = $composition->server?->runtime;
                    if ($manager === null || $runtime === null) {
                        return false;
                    }
                    $current = $manager->info($world)->spawn;
                    $previous = new \Bedriox\Api\World\BlockPosition((int) floor($current->x), (int) floor($current->y), (int) floor($current->z));
                    $event = new \Bedriox\Api\Event\World\WorldSpawnChangeEvent($world, $previous, $position);
                    $pluginEvents->dispatch($event);
                    if ($event->isCancelled() || !$runtime->setWorldSpawn($world, $position)) {
                        return false;
                    }
                    $pluginEvents->dispatch(new \Bedriox\Api\Event\World\WorldSpawnChangedEvent($world, $previous, $position));
                    return true;
                },
                setPlayerSpawnPoint: static function (
                    \Bedriox\Api\Player\Player $player,
                    \Bedriox\Api\World\Position $position,
                ) use ($composition, $pluginEvents): bool {
                    $event = new \Bedriox\Api\Event\Player\PlayerSpawnPointChangeEvent($player, $position);
                    $pluginEvents->dispatch($event);
                    if ($event->isCancelled()
                        || !($composition->server?->runtime->setPlayerSpawnPoint($player->uuid, $position) ?? false)) {
                        return false;
                    }
                    $pluginEvents->dispatch(new \Bedriox\Api\Event\Player\PlayerSpawnPointChangedEvent($player, $position));
                    return true;
                },
                setAutosave: static fn(bool $enabled): bool =>
                    $composition->server?->runtime->setAutosaveEnabled($enabled) ?? false,
                bans: $bans,
                playerAddress: static fn(string $name): ?string =>
                    $composition->server?->runtime->remoteAddressForPlayer($name),
                kickAddress: static fn(string $address, string $reason, string $actor): int =>
                    $composition->server?->runtime->kickAddress($address, $reason, $actor) ?? 0,
                latestUpdate: static fn(): ?\Bedriox\Api\Update\UpdateInfo =>
                    $composition->updates?->latestAvailable(),
            ))->register();
            $startupCompositionStartedAt = hrtime(true);
            $server = (new ServerBootstrap(
                $worldFactory,
                playerDataDirectory: $workingDirectory . DIRECTORY_SEPARATOR . 'player_data',
                playerDataStore: $playerStore,
            ))->create(
                $config,
                null,
                $diagnostics,
                $this->crashContextProvider instanceof CrashContextPublisher ? $this->crashContextProvider : null,
                $pluginEvents,
                $pluginHost->commands(),
                $permissionStore,
                $itemCatalog,
                $performance,
                $pluginHost->tickScheduler(...),
                $coreWorkers,
                entityDefinitions: $entityDefinitions,
                pluginEntityLifecycle: $composition->entityLifecycle,
                pluginActions: $pluginHost->actions(),
                whitelist: $whitelist,
                bans: $bans,
                playerLifecycleLogger: new PlayerLifecycleLogger($logger),
                data: $data,
                internalStates: $internalStates,
                blockCatalog: $blockCatalog,
            );
            $startupCompositionReadyAt = hrtime(true);
            $diagnostics->record('runtime.startup.protocol_trace', [
                'catalogs_us' => intdiv($startupCatalogsReadyAt - $startupStartedAt, 1_000),
                'application_wiring_us' => intdiv($startupCompositionStartedAt - $startupCatalogsReadyAt, 1_000),
                'server_composition_us' => intdiv($startupCompositionReadyAt - $startupCompositionStartedAt, 1_000),
                'time_to_listen_us' => intdiv($startupCompositionReadyAt - $startupStartedAt, 1_000),
            ]);
            $composition->server = $server;
            $updates = new UpdateManager(
                self::VERSION,
                $config->updatesEnabled,
                $config->updateOperatorNotifications,
                $coreWorkers,
                static fn(): array => $server->runtime->onlinePlayers(),
                static fn(string $uuid, string $permission): bool =>
                    $permissionStore->hasPermission($uuid, $permission),
                static function (\Bedriox\Api\Event\Server\UpdateAvailableEvent $event) use ($pluginEvents): void {
                    $pluginEvents->dispatch($event);
                },
                static function (string $message) use ($logger): void {
                    $logger->notice($message, 'Update');
                },
                static function (string $message) use ($logger): void {
                    $logger->debug($message, 'Update');
                },
            );
            $composition->updates = $updates;
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
            $logger->info(sprintf('Bedriox is listening on %s:%d', $server->localAddress, $server->localPort));
            $logger->info('Preparing spawn area in the background...');
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
                    backgroundPoll: static function () use ($coreWorkers, $backgroundLog, $updates): void {
                        $coreWorkers->pollWithinBudget(256, 5_000_000);
                        $updates->tick();
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
        } catch (PortUnavailableException $exception) {
            $error($exception->getMessage() . PHP_EOL);
            $error('Stop the other server or change server-port in server.properties, then start Bedriox again.' . PHP_EOL);
            $this->waitForInteractiveExit($output);

            return 1;
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

    private function banner(): string
    {
        return <<<'BANNER'
 ____           _      _
| __ )  ___  __| |_ __(_) _____  __
|  _ \ / _ \/ _` | '__| |/ _ \ \/ /
| |_) |  __/ (_| | |  | | (_) >  <
|____/ \___|\__,_|_|  |_|\___/_/\_\

BANNER
            . $this->displayName() . "\n"
            . "Minecraft: Bedrock Edition Server Software\n"
            . "Website: https://bedriox.com\n"
            . "Copyright (C) 2026 Veno Ninja LLC\n";
    }

    /** Keeps a double-clicked Windows launcher open long enough to read a bind failure. */
    private function waitForInteractiveExit(Closure $output): void
    {
        if (!defined('STDIN') || !is_resource(STDIN)
            || !function_exists('stream_isatty') || !stream_isatty(STDIN)) {
            return;
        }
        $output('Press Enter to close Bedriox.' . PHP_EOL);
        fgets(STDIN);
    }

    private static function updateServerProperty(string $path, string $key, string $value): void
    {
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Server properties must be a regular file.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to read server properties.');
        }
        $replacement = $key . '=' . $value;
        $updated = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $replacement, $contents, 1, $count);
        if (!is_string($updated)) {
            throw new \RuntimeException('Unable to update server properties.');
        }
        if ($count !== 1) {
            $updated = rtrim($updated, "\r\n") . PHP_EOL . $replacement . PHP_EOL;
        }
        $temporary = tempnam(dirname($path), '.properties-');
        if (!is_string($temporary)) {
            throw new \RuntimeException('Unable to create a temporary server properties file.');
        }
        try {
            if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated) || !rename($temporary, $path)) {
                throw new \RuntimeException('Unable to publish server properties.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
