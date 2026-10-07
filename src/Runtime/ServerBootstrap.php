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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\World as ApiWorld;
use Bedriox\Api\World\WorldCreationOptions;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Discovery\AdvertisedGameMode;
use Bedriox\Protocol\Discovery\BedrockServerAdvertisement;
use Bedriox\Protocol\Identity\ClientDataJwtVerifier;
use Bedriox\Protocol\Security\EphemeralKeyFactory;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\RakNet\DiscoveryStatus;
use Bedriox\RakNet\TransportConfig;
use Bedriox\Server\Access\BanManager;
use Bedriox\Server\Access\WhitelistManager;
use Bedriox\Server\Authentication\Discovery\CurlHttpsJsonTransport;
use Bedriox\Server\Authentication\Discovery\MinecraftDiscoveryJwkProvider;
use Bedriox\Server\Authentication\FullTokenAuthenticator;
use Bedriox\Server\Authentication\SystemAuthenticationClock;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Crafting\RecipeItemTagRegistry;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Potion\BrewingRecipeCatalog;
use Bedriox\Server\Gameplay\Processing\FurnaceRecipeCatalog;
use Bedriox\Server\Gameplay\Processing\TransientWorkstationProcessor;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\DevelopmentLoginAuthenticator;
use Bedriox\Server\Login\ExplicitSelfSignedLoginAuthenticator;
use Bedriox\Server\Login\FullLoginAuthenticatorAdapter;
use Bedriox\Server\Login\LazyFullLoginAuthenticator;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\SecureHandshakeMaterialFactory;
use Bedriox\Server\Login\SystemMonotonicClock;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\Memory\EmergencyMemoryReserve;
use Bedriox\Server\Observability\Memory\GarbageCollector;
use Bedriox\Server\Observability\Memory\MemoryManager;
use Bedriox\Server\Observability\Memory\PhpGarbageCollectorBackend;
use Bedriox\Server\Observability\Memory\PhpMemoryUsageProvider;
use Bedriox\Server\Observability\PerformanceMonitor;
use Bedriox\Server\Observability\PlayerLifecycleLogger;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Player\Persistence\FilePlayerDataStore;
use Bedriox\Server\Player\Persistence\PlayerDataStore;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginEntityLifecycleBridge;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationLimits;
use Bedriox\Server\Simulation\SimulationPluginApiBackend;
use Bedriox\Server\Simulation\SystemSimulationClock;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Transport\ProcessDiscoveryServerTransport;
use Bedriox\Server\Worker\Chunk\AsyncChunkGenerator;
use Bedriox\Server\Worker\Chunk\ChunkProjectionIdentity;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\Network\CachingCompressionWorkerDispatcher;
use Bedriox\Server\Worker\Network\ManagedCompressionWorkerDispatcher;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\DefaultBlockPalette;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\ChunkUnloadManager;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generator\GeneratorExecution;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldGeneratorFactory;
use Bedriox\Server\World\WorldMetadata;
use Closure;
use Throwable;

/** Fail-closed production dependency composition; FULL discovery completes before UDP bind. */
final class ServerBootstrap
{
    public const string SELF_SIGNED_WARNING = 'WARNING: SELF_SIGNED authentication is insecure and intended only for isolated development.';

    public function __construct(
        private readonly ?ConfiguredWorldFactory $worldFactory = null,
        private readonly ?EphemeralKeyFactory $ephemeralKeys = null,
        private readonly ?string $playerDataDirectory = null,
        private readonly ?PlayerDataStore $playerDataStore = null,
    ) {}

    public function create(
        ServerConfig $config,
        ?PlayInitializationFactory $initialization = null,
        ?RuntimeDiagnostics $diagnostics = null,
        ?CrashContextPublisher $crashContext = null,
        ?PluginGameplayEventBridge $pluginEvents = null,
        ?CommandRegistry $commandRegistry = null,
        ?PermissionStore $permissionStore = null,
        ?ItemCatalog $itemCatalog = null,
        ?PerformanceMonitor $performance = null,
        ?Closure $simulationTickBoundary = null,
        ?ManagedWorkerDispatcher $workers = null,
        ?EntityDefinitionRegistry $entityDefinitions = null,
        ?PluginEntityLifecycleBridge $pluginEntityLifecycle = null,
        ?PluginActionBuffer $pluginActions = null,
        ?WhitelistManager $whitelist = null,
        ?BanManager $bans = null,
        ?PlayerLifecycleLogger $playerLifecycleLogger = null,
    ): BootstrappedServer {
        $diagnostics ??= RuntimeDiagnostics::disabled();
        $authenticationClock = new SystemAuthenticationClock();
        $authenticator = $this->authenticator($config->authenticationMode, $authenticationClock);
        $configuredOpenSsl = getenv('OPENSSL_CONF');
        $openSslConfiguration = OpenSslConfiguration::discover(
            PHP_BINARY,
            is_string($configuredOpenSsl) && $configuredOpenSsl !== '' ? $configuredOpenSsl : null,
        );
        $ephemeralKeys = $this->ephemeralKeys ?? new OpenSslEphemeralKeyFactory($openSslConfiguration);
        // A listener that cannot complete Bedrock's P-384 handshake is not joinable.
        // Qualify the exact production key factory before opening the world or binding UDP.
        $ephemeralKeys->generate();
        $garbageCollector = new GarbageCollector(new PhpGarbageCollectorBackend());
        $memoryManager = $config->memoryManagementEnabled
            ? new MemoryManager(
                new PhpMemoryUsageProvider($config->memoryLimitBytes),
                new EmergencyMemoryReserve(),
                elevatedPercent: $config->memorySoftThreshold,
                highPercent: $config->memoryHighThreshold,
                criticalPercent: $config->memoryCriticalThreshold,
                elevatedUnloadBudget: $config->chunkUnloadPerTick,
                highUnloadBudget: min(1_024, $config->chunkUnloadPerTick * 2),
                criticalUnloadBudget: min(1_024, $config->chunkUnloadPerTick * 4),
            )
            : null;
        $runtimeLimits = new RuntimeLimits(
            maximumSessions: $config->maximumPlayers,
            maximumChunkRadius: $config->viewDistance,
            preloadedChunkRadius: $config->spawnRadius,
            maximumStreamingPacketsPerPoll: $config->chunksSendPerTick,
        );
        $playerConnections = new PlayerConnectionDirectory();
        $pluginEvents = $pluginEvents?->withPlayerConnections($playerConnections->connection(...))
            ->withPlayerActions($playerConnections->actions(...))
            ->withPlayerInventoryActions(
                $playerConnections->inventoryActions(...),
                $playerConnections->maximumStackSize(...),
            )
            ->withPlayerEffectActions($playerConnections->effectActions(...))
            ->withPlayerExperienceActions($playerConnections->experienceActions(...));
        $worldHandleResolver = new WorldHandleResolver();
        $pluginEvents = $pluginEvents?->withWorldResolver($worldHandleResolver->resolve(...));
        $simulationLimits = new SimulationLimits(
            ticksPerSecond: $config->ticksPerSecond,
            maximumPlayers: $config->maximumPlayers,
            maximumQueuedLifecycleCommands: $config->maximumPlayers,
            maximumQueuedLifecycleBytes: max(65_536, $config->maximumPlayers * 144),
            movementSecurityEnabled: $config->movementSecurityEnabled,
            correctInvalidMovement: $config->correctInvalidMovement,
            kickRepeatedMovementViolations: $config->kickRepeatedMovementViolations,
        );
        $data = BedrockDataSet::bundled();
        // Admitted data accessors validate and materialize their immutable registries. Keep the
        // resulting instances for every world instead of rebuilding them during a live tick.
        $entityTypes = $data->entityTypeRegistry();
        $blockProperties = $data->blockPropertyRegistry();
        $entityDefinitions ??= EntityDefinitionRegistry::fromData($entityTypes);
        $networkStates = $data->blockStateRegistry();
        $internalStates = $this->worldFactory instanceof PersistentWorldFactory
            ? $this->worldFactory->internalStates($data)
            : new BlockStateRegistry($networkStates->states());
        $blockCatalog = BlockCatalog::vanilla($internalStates, $data->blockItemMappingRegistry());
        $itemCatalog ??= ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blockCatalog,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );
        $blockTranslator = new BlockNetworkTranslator($internalStates, $networkStates);
        $inventoryProjector = BedrockInventoryPacketProjector::fromData($data, $blockTranslator, $itemCatalog);
        $craftingCatalog = CraftingCatalog::fromData(
            $data,
            $itemCatalog,
            $internalStates,
            $inventoryProjector,
        );
        $brewingRecipes = new BrewingRecipeCatalog(
            $data->recipeRegistry()->containerMixes(),
            $data->recipeRegistry()->potionMixes(),
        );
        $furnaceRecipes = new FurnaceRecipeCatalog($data->recipeRegistry());
        $workstations = new TransientWorkstationProcessor(
            $data->recipeRegistry(),
            RecipeItemTagRegistry::vanilla($itemCatalog),
            $itemCatalog,
        );
        $flatPalette = FixedFlatBlockPalette::fromRegistry($internalStates);
        $defaultPalette = DefaultBlockPalette::fromRegistry($internalStates);
        $blockCollisions = BlockCollisionRegistry::forGenerationPalette(
            $internalStates,
            GenerationBlockPalette::fromRegistry($internalStates),
        );
        $chunkProjectionRegistryHash = ChunkProjectionIdentity::registryHash($data, $networkStates);
        $openedWorld = $this->worldFactory?->open($config, $data)
            ?? $this->ephemeralWorld($config, $internalStates);
        $flatWorld = $openedWorld->world;
        $runtimeWorldActions = new RuntimeWorldActions($blockCatalog, $internalStates, $pluginActions);
        $worldActions = $runtimeWorldActions->actions();
        $defaultWorldHandle = new ApiWorld(
            WorldRuntimeManager::canonicalId($config->levelName),
            1,
            $worldActions,
            array_map(
                static fn(OpenedWorld $dimension): \Bedriox\Api\World\WorldDimension => $dimension->world->dimension(),
                $openedWorld->dimensions(),
            ),
        );
        $generatorRegistry = $this->worldFactory instanceof PersistentWorldFactory
            ? $this->worldFactory->generators()
            : WorldGeneratorFactory::builtIns();
        $workerCount = $workers?->snapshot()->workerCount ?? 0;
        $workersAvailable = $workerCount > 0;
        $defaultGeneratorDefinition = $generatorRegistry->require(
            WorldGeneratorFactory::canonicalIdentifier($openedWorld->data->generatorName),
        );
        if ($defaultGeneratorDefinition->workerSource !== null && !$workersAvailable) {
            throw new \RuntimeException('Plugin world generators require an available generation worker.');
        }
        if ($workers !== null && $workersAvailable
            && $defaultGeneratorDefinition->execution === GeneratorExecution::WORKER) {
            $flatWorld->enableAsyncGeneration(new AsyncChunkGenerator(
                $workers,
                CoreWorkerTaskCatalog::GENERATE_CHUNK,
                $openedWorld->data->generatorName,
                $openedWorld->data->generatorVersion,
                $openedWorld->data->metadata->seed,
                $internalStates,
                options: GeneratorOptions::fromJson($openedWorld->data->generatorOptions),
                dimension: $flatWorld->dimension()->value,
                workerSource: $defaultGeneratorDefinition->workerSource,
                allowSynchronousFallback: $defaultGeneratorDefinition->workerSource === null,
            ));
        }
        $compressionWorkers = $workers !== null && $workersAvailable
            ? new CachingCompressionWorkerDispatcher(new ManagedCompressionWorkerDispatcher($workers))
            : null;
        $preparedPlayBatches = $compressionWorkers === null ? null : new PreparedPlayBatchCache(
            maximumBytes: min(
                33_554_432,
                max(8_388_608, intdiv($config->memoryLimitBytes === 0 ? 536_870_912 : $config->memoryLimitBytes, 16)),
            ),
        );
        $preparedChunks = $workers !== null && $workersAvailable
            ? new PreparedChunkCache(
                $workers,
                CoreWorkerTaskCatalog::PREPARE_CHUNK,
                $internalStates,
                bin2hex(random_bytes(16)),
                maximumEntries: min(4_096, $config->chunkCacheLimit),
                maximumBytes: min(
                    67_108_864,
                    max(8_388_608, intdiv($config->memoryLimitBytes === 0 ? 536_870_912 : $config->memoryLimitBytes, 8)),
                ),
                maximumPending: min($config->chunkGenerationQueueSize, $workerCount * 2),
                registryHash: $chunkProjectionRegistryHash,
                dimension: $flatWorld->dimension(),
            )
            : null;
        $discovery = null;
        try {
            $initialization ??= BedrockPlayInitializationFactory::forWorld(
                $data,
                $runtimeLimits,
                $openedWorld->data,
                $config->movementRewindHistorySize,
                $config->defaultGamemode,
                $itemCatalog,
                $craftingCatalog,
                $flatWorld->time(...),
                $flatWorld->dimension(),
            );
            $spawn = $flatWorld->spawn();
            $playerStore = $this->playerDataStore
                ?? ($this->playerDataDirectory === null ? null : new FilePlayerDataStore($this->playerDataDirectory));
            $playerPersistence = $playerStore === null ? null : new PlayerPersistenceManager(
                $playerStore,
                $defaultWorldHandle->id(),
                new Position($spawn->x, $spawn->y, $spawn->z),
                defaultGamemode: $config->defaultGamemode,
                itemCatalog: $itemCatalog,
            );
            $world = new WorldSimulation(
                $simulationLimits,
                new Position($spawn->x, $spawn->y, $spawn->z),
                $flatWorld,
                $flatPalette,
                $pluginEvents,
                $defaultPalette->water,
                $defaultPalette->lava,
                $playerPersistence,
                $config->pvp,
                $itemCatalog,
                $blockCatalog,
                $internalStates,
                $blockCollisions,
                craftingCatalog: $craftingCatalog,
                blockProperties: $blockProperties,
                entityAiEnabled: $config->entityAiEnabled,
                spawnAnimals: $config->spawnAnimals,
                spawnMonsters: $config->spawnMonsters,
                entityTypes: $entityTypes,
                entityDefinitions: $entityDefinitions,
                pluginEntityLifecycle: $pluginEntityLifecycle,
                pluginActions: $pluginActions,
                worldId: $defaultWorldHandle->id(),
                brewingRecipes: $brewingRecipes,
                furnaceRecipes: $furnaceRecipes,
                transientWorkstations: $workstations,
                dimension: $flatWorld->dimension(),
                navigationWorkers: $workersAvailable ? $workers : null,
            );
            $entityPersistenceStore = $flatWorld->entityPersistenceStore();
            if ($entityPersistenceStore !== null) {
                $world->enableEntityPersistence($entityPersistenceStore, $entityDefinitions);
            }
            $worldLoop = new FixedRateWorldLoop($world, new SystemSimulationClock(), maximumTicksPerPoll: 1);

            $composeManagedDimension = function (ApiWorld $handle, OpenedWorld $opened) use (
                $workers,
                $workersAvailable,
                $config,
                $internalStates,
                $simulationLimits,
                $flatPalette,
                $pluginEvents,
                $defaultPalette,
                $playerPersistence,
                $itemCatalog,
                $blockCatalog,
                $blockCollisions,
                $craftingCatalog,
                $brewingRecipes,
                $furnaceRecipes,
                $workstations,
                $entityDefinitions,
                $pluginEntityLifecycle,
                $pluginActions,
                $world,
                $generatorRegistry,
                $diagnostics,
                $blockProperties,
                $entityTypes,
                $chunkProjectionRegistryHash,
            ): ManagedWorldRuntime {
                $startedAt = hrtime(true);
                $internalWorld = $opened->world;
                $generatorDefinition = $generatorRegistry->require(
                    WorldGeneratorFactory::canonicalIdentifier($opened->data->generatorName),
                );
                $generatorLookupAt = hrtime(true);
                if ($generatorDefinition->workerSource !== null && !$workersAvailable) {
                    throw new \RuntimeException('Plugin world generators require an available generation worker.');
                }
                if ($workers !== null && $workersAvailable
                    && $generatorDefinition->execution === GeneratorExecution::WORKER) {
                    $internalWorld->enableAsyncGeneration(new AsyncChunkGenerator(
                        $workers,
                        CoreWorkerTaskCatalog::GENERATE_CHUNK,
                        $opened->data->generatorName,
                        $opened->data->generatorVersion,
                        $opened->data->metadata->seed,
                        $internalStates,
                        options: GeneratorOptions::fromJson($opened->data->generatorOptions),
                        dimension: $internalWorld->dimension()->value,
                        workerSource: $generatorDefinition->workerSource,
                        allowSynchronousFallback: $generatorDefinition->workerSource === null,
                    ));
                }
                $generatorWiringAt = hrtime(true);
                $worldPreparedChunks = $workers !== null && $workersAvailable
                    ? new PreparedChunkCache(
                        $workers,
                        CoreWorkerTaskCatalog::PREPARE_CHUNK,
                        $internalStates,
                        bin2hex(random_bytes(16)),
                        maximumEntries: min(4_096, $config->chunkCacheLimit),
                        maximumBytes: min(
                            67_108_864,
                            max(8_388_608, intdiv(
                                $config->memoryLimitBytes === 0 ? 536_870_912 : $config->memoryLimitBytes,
                                8,
                            )),
                        ),
                        maximumPending: min($config->chunkGenerationQueueSize, $workers->snapshot()->workerCount * 2),
                        registryHash: $chunkProjectionRegistryHash,
                        dimension: $internalWorld->dimension(),
                    )
                    : null;
                $chunkCacheAt = hrtime(true);
                $spawn = $internalWorld->spawn();
                $simulation = new WorldSimulation(
                    $simulationLimits,
                    new Position($spawn->x, $spawn->y, $spawn->z),
                    $internalWorld,
                    $flatPalette,
                    $pluginEvents,
                    $defaultPalette->water,
                    $defaultPalette->lava,
                    $playerPersistence,
                    $config->pvp,
                    $itemCatalog,
                    $blockCatalog,
                    $internalStates,
                    $blockCollisions,
                    itemBehaviors: $world->itemBehaviorRegistry(),
                    craftingCatalog: $craftingCatalog,
                    blockProperties: $blockProperties,
                    entityAiEnabled: $config->entityAiEnabled,
                    spawnAnimals: $config->spawnAnimals,
                    spawnMonsters: $config->spawnMonsters,
                    entityTypes: $entityTypes,
                    entityDefinitions: $entityDefinitions,
                    pluginEntityLifecycle: $pluginEntityLifecycle,
                    pluginActions: $pluginActions,
                    worldId: $handle->id(),
                    brewingRecipes: $brewingRecipes,
                    furnaceRecipes: $furnaceRecipes,
                    transientWorkstations: $workstations,
                    dimension: $internalWorld->dimension(),
                    navigationWorkers: $workersAvailable ? $workers : null,
                );
                $simulationAt = hrtime(true);
                $entityStore = $internalWorld->entityPersistenceStore();
                if ($entityStore !== null) {
                    $simulation->enableEntityPersistence($entityStore, $entityDefinitions);
                }
                $entityPersistenceAt = hrtime(true);

                $runtime = new ManagedWorldRuntime(
                    $handle,
                    $opened,
                    $simulation,
                    new FixedRateWorldLoop($simulation, new SystemSimulationClock(), maximumTicksPerPoll: 1),
                    $worldPreparedChunks,
                );
                $completedAt = hrtime(true);
                $diagnostics->record('runtime.world_composition.protocol_trace', [
                    'world' => $handle->id(),
                    'generator_lookup_us' => intdiv($generatorLookupAt - $startedAt, 1_000),
                    'generator_wiring_us' => intdiv($generatorWiringAt - $generatorLookupAt, 1_000),
                    'prepared_cache_us' => intdiv($chunkCacheAt - $generatorWiringAt, 1_000),
                    'simulation_us' => intdiv($simulationAt - $chunkCacheAt, 1_000),
                    'entity_persistence_us' => intdiv($entityPersistenceAt - $simulationAt, 1_000),
                    'finalize_us' => intdiv($completedAt - $entityPersistenceAt, 1_000),
                    'total_us' => intdiv($completedAt - $startedAt, 1_000),
                ]);

                return $runtime;
            };

            $composeManagedWorld = static function (
                ApiWorld $handle,
                OpenedWorld $opened,
            ) use ($composeManagedDimension): ManagedWorldRuntime {
                $root = $composeManagedDimension($handle, $opened);
                $additional = [];
                foreach ($opened->dimensions() as $dimensionOpened) {
                    if ($dimensionOpened === $opened) {
                        continue;
                    }
                    $additional[] = $composeManagedDimension($handle, $dimensionOpened);
                }

                return new ManagedWorldRuntime(
                    $handle,
                    $root->opened,
                    $root->simulation,
                    $root->loop,
                    $root->preparedChunks,
                    $additional,
                );
            };

            $defaultAdditionalDimensions = [];
            foreach ($openedWorld->dimensions() as $dimensionOpened) {
                if ($dimensionOpened === $openedWorld) {
                    continue;
                }
                $defaultAdditionalDimensions[] = $composeManagedDimension($defaultWorldHandle, $dimensionOpened);
            }
            $defaultManagedWorld = new ManagedWorldRuntime(
                $defaultWorldHandle,
                $openedWorld,
                $world,
                $worldLoop,
                $preparedChunks,
                $defaultAdditionalDimensions,
            );
            $worldRuntimes = new WorldRuntimeManager(
                $defaultWorldHandle->id(),
                $defaultManagedWorld,
                actions: $worldActions,
            );
            $runtimeWorldActions->attach($worldRuntimes);
            $worldHandleResolver->attach($worldRuntimes);
            $worldOperations = new WorldOperationQueue();

            $persistentWorldFactory = $this->worldFactory instanceof PersistentWorldFactory
                ? $this->worldFactory
                : null;
            if ($persistentWorldFactory !== null && $workers !== null && $workersAvailable) {
                $persistentWorldFactory->useProcessLauncher(
                    $workers,
                    CoreWorkerTaskCatalog::SPAWN_WORLD_STORAGE_OWNER,
                );
                $persistentWorldFactory->useWorldPreparationWorker(
                    $workers,
                    CoreWorkerTaskCatalog::PREPARE_WORLD,
                );
            }
            $publicWorldManager = new RuntimeWorldManager(
                $worldRuntimes,
                $worldOperations,
                static function (ApiWorld $handle, WorldCreationOptions $options) use (
                    $persistentWorldFactory,
                    $config,
                    $data,
                    $composeManagedWorld,
                ): PendingManagedWorldRuntime {
                    if ($persistentWorldFactory === null) {
                        throw new \RuntimeException('Named world creation requires persistent world storage.');
                    }

                    return new PendingManagedWorldRuntime(
                        $persistentWorldFactory->beginCreateNamed($config, $data, $handle->id(), $options),
                        static fn(OpenedWorld $opened): ManagedWorldRuntime => $composeManagedWorld($handle, $opened),
                    );
                },
                static function (ApiWorld $handle) use (
                    $persistentWorldFactory,
                    $config,
                    $data,
                    $composeManagedWorld,
                ): PendingManagedWorldRuntime {
                    if ($persistentWorldFactory === null) {
                        throw new \RuntimeException('Named world loading requires persistent world storage.');
                    }

                    return new PendingManagedWorldRuntime(
                        $persistentWorldFactory->beginLoadNamed($config, $data, $handle->id()),
                        static fn(OpenedWorld $opened): ManagedWorldRuntime => $composeManagedWorld($handle, $opened),
                    );
                },
                $pluginEvents === null ? null : $pluginEvents->dispatch(...),
            );
            $chunkSerializer = new BedrockChunkPacketSerializer(
                $blockTranslator,
            );
            $serverGuid = random_int(1, PHP_INT_MAX);
            $advertisement = new BedrockServerAdvertisement(
                motd: $config->serverName,
                onlinePlayers: 0,
                maximumPlayers: $config->maximumPlayers,
                serverId: $serverGuid,
                subMotd: $config->motd,
                gameMode: AdvertisedGameMode::Survival,
                nintendoLimited: false,
                ipv4Port: $config->port,
            );
            $status = new DiscoveryStatus(
                $advertisement->encode(),
                acceptingConnections: true,
            );
            $transportConfig = new TransportConfig(
                bindAddress: $config->bindAddress,
                port: $config->port,
                maximumSessions: $config->maximumPlayers,
                maximumPendingHandshakes: $config->maximumPlayers,
                handshakeTimeoutMilliseconds: 15_000,
                connectedSessionMaintenanceIntervalMilliseconds: 50,
                maximumReceivedPayloads: 65_535,
                maximumReceivedPayloadBytes: 67_108_864,
                maximumPendingOutboundDatagrams: 65_535,
                maximumPendingOutboundBytes: 67_108_864,
                maximumSessionEvents: max($config->maximumPlayers, 1_024),
                socketReceiveBufferBytes: 67_108_864,
                socketSendBufferBytes: 67_108_864,
                security: $config->transportSecurityPolicy,
            );
            $discovery = ProcessDiscoveryServerTransport::start(
                $transportConfig,
                $serverGuid,
                $status,
                $diagnostics,
            );
            $runtime = new ServerRuntime(
                $discovery,
                new ConfiguredLoginChannelFactory(
                    new SystemMonotonicClock(),
                    $authenticator,
                    new SecureHandshakeMaterialFactory($ephemeralKeys),
                    $config->authenticationMode,
                ),
                new BedrockPlayChannelFactory(
                    $initialization,
                    limits: $runtimeLimits,
                    diagnostics: $diagnostics,
                    world: $flatWorld,
                    chunkSerializer: $chunkSerializer,
                    viewDistance: $config->viewDistance,
                    spawnRadius: $config->spawnRadius,
                    chunksGeneratePerTick: $config->chunksGeneratePerTick,
                    chunksSendPerTick: $config->chunksSendPerTick,
                    chunkPrefetchRadius: $config->chunkLoadingPrefetchRadius,
                    chunkGenerationQueueSize: $config->chunkGenerationQueueSize,
                    inventoryProjector: $inventoryProjector,
                    commandRegistry: $commandRegistry,
                    permissionStore: $permissionStore,
                    compressionWorkers: $compressionWorkers,
                    compressionTaskTypeId: CoreWorkerTaskCatalog::COMPRESS_BATCH,
                    preparedChunks: $preparedChunks,
                    preparedPlayBatches: $preparedPlayBatches,
                    worldBinding: static function (\Bedriox\Server\Player\PlayerBootstrap $bootstrap) use (
                        $worldRuntimes,
                        $initialization,
                        $data,
                        $runtimeLimits,
                        $config,
                        $itemCatalog,
                        $craftingCatalog,
                    ): ?array {
                        $runtime = $worldRuntimes->get($bootstrap->worldName, $bootstrap->dimension);
                        if ($runtime === null) {
                            return null;
                        }
                        $worldInitialization = $initialization instanceof BedrockPlayInitializationFactory
                            ? BedrockPlayInitializationFactory::forWorld(
                                $data,
                                $runtimeLimits,
                                $runtime->opened->data,
                                $config->movementRewindHistorySize,
                                $config->defaultGamemode,
                                $itemCatalog,
                                $craftingCatalog,
                                $runtime->opened->world->time(...),
                                $runtime->opened->world->dimension(),
                            )
                            : $initialization;

                        return [
                            'initialization' => $worldInitialization,
                            'world' => $runtime->opened->world,
                            'preparedChunks' => $runtime->preparedChunks,
                        ];
                    },
                ),
                $world,
                $worldLoop,
                new BedrockWorldEventPacketEncoder($chunkSerializer, $inventoryProjector),
                $runtimeLimits,
                diagnostics: $diagnostics,
                crashContext: $crashContext,
                persistentWorld: $openedWorld->world,
                autosaveIntervalTicks: $config->levelAutosaveIntervalTicks,
                autosaveChunkBudget: $config->chunksSavePerTick,
                playerPersistence: $playerPersistence,
                playerAutosaveIntervalTicks: $config->playersAutosaveIntervalTicks,
                playerAutosaveBudget: $config->playersSavePerTick,
                commandRegistry: $commandRegistry,
                permissionStore: $permissionStore,
                playerConnections: $playerConnections,
                inventoryProjector: $inventoryProjector,
                pluginEvents: $pluginEvents,
                simulationTickBoundary: $simulationTickBoundary,
                performance: $performance,
                preparedChunks: $preparedChunks,
                memoryManager: $memoryManager,
                garbageCollector: $garbageCollector,
                chunkUnloadPerTick: $config->chunkUnloadPerTick,
                craftingCatalog: $craftingCatalog,
                chunksGeneratePerTick: $config->chunksGeneratePerTick,
                chunksSendPerTick: $config->chunksSendPerTick,
                worldRuntimes: $worldRuntimes,
                worldOperations: $worldOperations,
                itemCatalog: $itemCatalog,
                blockStateRegistry: $internalStates,
                pluginActions: $pluginActions,
                whitelist: $whitelist,
                bans: $bans,
                playerLifecycleLogger: $playerLifecycleLogger,
            );
        } catch (Throwable $exception) {
            $discovery?->close();
            try {
                $flatWorld->close();
            } catch (Throwable) {
                // Preserve the composition failure while still attempting provider cleanup.
            }
            throw $exception;
        }

        return new BootstrappedServer(
            $runtime,
            $discovery->localAddress(),
            $discovery->localPort(),
            $config->authenticationMode === AuthenticationMode::SELF_SIGNED ? self::SELF_SIGNED_WARNING : null,
            new SimulationPluginApiBackend(
                $world,
                $itemCatalog,
                $internalStates,
                $worldRuntimes,
                $publicWorldManager,
                $whitelist,
            ),
            $flatWorld,
            $craftingCatalog,
            $publicWorldManager,
        );
    }

    private function ephemeralWorld(ServerConfig $config, BlockStateRegistry $states): OpenedWorld
    {
        $spawnOverride = $config->spawnX === null ? null : new SpawnPosition(
            $config->spawnX,
            $config->spawnY ?? 64,
            $config->spawnZ ?? 0,
        );
        $metadata = new WorldMetadata($config->levelName, $config->levelSeed);
        $generator = WorldGeneratorFactory::create($config->levelGenerator, $config->levelSeed, $states);
        $world = new World(
            $metadata,
            $generator,
            new ChunkRepository($config->chunkCacheLimit),
            $spawnOverride,
            chunkUnloads: new ChunkUnloadManager(
                intdiv($config->chunkUnloadGraceTicks * 1_000_000_000, $config->ticksPerSecond),
            ),
        );

        return new OpenedWorld($world, new WorldData(
            $metadata,
            $world->generatorName(),
            $world->spawn(),
            difficulty: match ($config->difficulty) {
                'peaceful' => 0,
                'easy' => 1,
                'normal' => 2,
                'hard' => 3,
                default => throw new \LogicException('Validated difficulty became unsupported.'),
            },
        ));
    }

    private function authenticator(AuthenticationMode $mode, SystemAuthenticationClock $clock): LoginAuthenticator
    {
        $clientData = new ClientDataJwtVerifier();
        if ($mode === AuthenticationMode::SELF_SIGNED) {
            return new DevelopmentLoginAuthenticator(
                new LazyFullLoginAuthenticator(fn(): LoginAuthenticator => $this->fullAuthenticator($clock, $clientData)),
                new ExplicitSelfSignedLoginAuthenticator($clock, $clientData),
            );
        }

        return $this->fullAuthenticator($clock, $clientData);
    }

    private function fullAuthenticator(
        SystemAuthenticationClock $clock,
        ClientDataJwtVerifier $clientData,
    ): LoginAuthenticator {
        $provider = new MinecraftDiscoveryJwkProvider(new CurlHttpsJsonTransport(), $clock);
        $provider->refresh(true);
        $provider->keys();

        return new FullLoginAuthenticatorAdapter(new FullTokenAuthenticator($provider, $clock, $clientData));
    }
}
