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

use Bedriox\Api\World\WorldCreationOptions;
use Bedriox\Api\World\WorldDifficulty;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\Persistence\World\ProcessWorldProvider;
use Bedriox\Server\Persistence\World\ProcessWorldProviderFactory;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\World\PendingWorldPreparation;
use Bedriox\Server\Worker\World\WorldPreparationRequest;
use Bedriox\Server\Worker\World\WorldPreparationResult;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\ChunkUnloadManager;
use Bedriox\Server\World\Generator\DeferredWorldGenerator;
use Bedriox\Server\World\Generator\GeneratorExecution;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\GeneratorRegistry;
use Bedriox\Server\World\Provider\LevelDbWorldProviderFactory;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WorldProviderFactory;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldGeneratorFactory;
use Bedriox\Server\World\WorldMetadata;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WeakMap;

/** Opens the configured Mojang LevelDB world before network admission begins. */
final class PersistentWorldFactory implements ConfiguredWorldFactory
{
    private string $worldsPath;
    private GeneratorRegistry $generators;
    /** @var WeakMap<BedrockDataSet, BlockStateRegistry> */
    private WeakMap $internalStates;
    /** @var WeakMap<BedrockDataSet, PersistentBlockStateRegistry> */
    private WeakMap $persistentStates;
    private ?WorkerDispatcher $worldPreparationWorkers = null;
    private int $worldPreparationTaskType = 0;

    public function __construct(
        string $workingDirectory,
        private WorldProviderFactory $providers = new LevelDbWorldProviderFactory(),
        ?GeneratorRegistry $generators = null,
    ) {
        if ($workingDirectory === '' || str_contains($workingDirectory, "\0")) {
            throw new InvalidArgumentException('Working directory must be a non-empty filesystem path.');
        }
        $this->worldsPath = rtrim($workingDirectory, "\\/") . DIRECTORY_SEPARATOR . 'worlds';
        $this->generators = $generators ?? WorldGeneratorFactory::builtIns();
        $this->internalStates = new WeakMap();
        $this->persistentStates = new WeakMap();
    }

    /** @internal Shared registry used by world creation, loading, and plugin registration. */
    public function generators(): GeneratorRegistry
    {
        return $this->generators;
    }

    /** @internal One immutable canonical registry is shared by every world opened from the same data set. */
    public function internalStates(BedrockDataSet $data): BlockStateRegistry
    {
        return $this->internalStates[$data] ??= new BlockStateRegistry($data->blockStateRegistry()->states());
    }

    /** @internal One immutable persistence registry is shared by every world opened from the same data set. */
    public function persistentStates(BedrockDataSet $data): PersistentBlockStateRegistry
    {
        return $this->persistentStates[$data] ??= $data->persistentBlockStateRegistry();
    }

    /** @internal Moves dynamic provider process launches off the authoritative runtime thread. */
    public function useProcessLauncher(WorkerDispatcher $launcher, int $taskTypeId): void
    {
        if ($this->providers instanceof ProcessWorldProviderFactory) {
            $this->providers->useProcessLauncher($launcher, $taskTypeId);
        }
    }

    /** @internal Moves generator construction and default-spawn discovery off the authoritative runtime thread. */
    public function useWorldPreparationWorker(WorkerDispatcher $workers, int $taskTypeId): void
    {
        if ($taskTypeId < 1 || $taskTypeId > 65_535) {
            throw new InvalidArgumentException('World preparation task type is invalid.');
        }
        $this->worldPreparationWorkers = $workers;
        $this->worldPreparationTaskType = $taskTypeId;
    }

    public function open(ServerConfig $config, BedrockDataSet $data): OpenedWorld
    {
        return $this->openPath(
            $config,
            $data,
            $config->levelName,
            createIfMissing: true,
        );
    }

    /** Creates a new named world without changing the configured default-world settings. */
    public function createNamed(
        ServerConfig $config,
        BedrockDataSet $data,
        string $worldId,
        WorldCreationOptions $options,
    ): OpenedWorld {
        $spawn = $options->spawn;
        $seed = $options->seed ?? random_int(PHP_INT_MIN, PHP_INT_MAX);
        if ($spawn !== null && (
            floor($spawn->x) !== $spawn->x
            || floor($spawn->y) !== $spawn->y
            || floor($spawn->z) !== $spawn->z
        )) {
            throw new InvalidArgumentException('Persisted world spawn coordinates must be whole block coordinates.');
        }

        return $this->openPath(
            $config,
            $data,
            $worldId,
            createIfMissing: true,
            failIfExists: true,
            displayName: $options->displayName ?? $worldId,
            seed: $seed,
            generatorName: $options->generator,
            difficulty: self::apiDifficulty($options->difficulty),
            initialTime: $options->initialTime,
            configuredSpawn: $spawn === null ? null : new SpawnPosition((int) $spawn->x, (int) $spawn->y, (int) $spawn->z),
            generatorOptions: new GeneratorOptions($options->generatorOptions),
        );
    }

    /** Begins named-world storage creation without waiting for the provider owner to open LevelDB. */
    public function beginCreateNamed(
        ServerConfig $config,
        BedrockDataSet $data,
        string $worldId,
        WorldCreationOptions $options,
    ): PendingOpenedWorld {
        $spawn = $options->spawn;
        $seed = $options->seed ?? random_int(PHP_INT_MIN, PHP_INT_MAX);
        if ($spawn !== null && (
            floor($spawn->x) !== $spawn->x
            || floor($spawn->y) !== $spawn->y
            || floor($spawn->z) !== $spawn->z
        )) {
            throw new InvalidArgumentException('Persisted world spawn coordinates must be whole block coordinates.');
        }
        $providers = $this->providers;
        if (!$providers instanceof ProcessWorldProviderFactory) {
            throw new RuntimeException('Asynchronous named-world creation requires process-owned world storage.');
        }

        $worldsPath = $this->ensureWorldsDirectory();
        $directoryName = self::safeDirectoryName($worldId);
        $worldPath = $worldsPath . DIRECTORY_SEPARATOR . $directoryName;
        $this->assertContainedExistingPath($worldsPath, $worldPath);
        if (file_exists($worldPath)) {
            throw new RuntimeException("World '$worldId' already exists.");
        }

        $workers = $this->worldPreparationWorkers
            ?? throw new RuntimeException('Asynchronous named-world creation requires a world preparation worker.');
        $internalStates = $this->internalStates($data);
        $configuredSpawn = $spawn === null ? null : new SpawnPosition((int) $spawn->x, (int) $spawn->y, (int) $spawn->z);
        $generatorOptions = new GeneratorOptions($options->generatorOptions);
        $identifier = WorldGeneratorFactory::canonicalIdentifier($options->generator);
        $definition = $this->generators->require($identifier);
        $preparation = new PendingWorldPreparation(
            $workers,
            $this->worldPreparationTaskType,
            new WorldPreparationRequest(
                $options->generator,
                $identifier,
                $definition->version,
                $seed,
                'minecraft:overworld',
                $generatorOptions,
                $definition->workerSource,
            ),
        );

        return new PendingOpenedWorld(
            $preparation,
            fn(ProcessWorldProvider $ready, ?WorldPreparationResult $prepared): OpenedWorld => $this->compose(
                $config,
                $ready,
                $internalStates,
                $configuredSpawn,
                $prepared,
            ),
            static fn(WorldPreparationResult $prepared): ProcessWorldProvider => $providers->beginCreate(
                $worldPath,
                new WorldData(
                    new WorldMetadata($options->displayName ?? $worldId, $seed),
                    $options->generator,
                    $configuredSpawn ?? $prepared->defaultSpawn,
                    time: $options->initialTime,
                    difficulty: self::apiDifficulty($options->difficulty),
                    generatorVersion: $prepared->generatorVersion,
                    generatorOptions: $generatorOptions->canonicalJson(),
                ),
                $internalStates,
            ),
        );
    }

    /** Begins named-world storage loading without waiting for the provider owner to open LevelDB. */
    public function beginLoadNamed(
        ServerConfig $config,
        BedrockDataSet $data,
        string $worldId,
    ): PendingOpenedWorld {
        $providers = $this->providers;
        if (!$providers instanceof ProcessWorldProviderFactory) {
            throw new RuntimeException('Asynchronous named-world loading requires process-owned world storage.');
        }
        $worldsPath = $this->ensureWorldsDirectory();
        $directoryName = $this->resolveExistingDirectoryName($worldsPath, self::safeDirectoryName($worldId));
        $worldPath = $worldsPath . DIRECTORY_SEPARATOR . $directoryName;
        $this->assertContainedExistingPath($worldsPath, $worldPath);
        if (!file_exists($worldPath)) {
            throw new RuntimeException("World '$worldId' does not exist.");
        }
        $internalStates = $this->internalStates($data);
        $provider = $providers->beginOpen(
            $worldPath,
            $internalStates,
        );

        return new PendingOpenedWorld(
            $provider,
            fn(ProcessWorldProvider $ready, ?WorldPreparationResult $_prepared): OpenedWorld => $this->compose(
                $config,
                $ready,
                $internalStates,
                null,
            ),
        );
    }

    /** Loads an existing named world and never creates replacement terrain when storage is absent. */
    public function loadNamed(ServerConfig $config, BedrockDataSet $data, string $worldId): OpenedWorld
    {
        return $this->openPath($config, $data, $worldId, createIfMissing: false);
    }

    private function openPath(
        ServerConfig $config,
        BedrockDataSet $data,
        string $worldId,
        bool $createIfMissing,
        bool $failIfExists = false,
        ?string $displayName = null,
        ?int $seed = null,
        ?string $generatorName = null,
        ?int $difficulty = null,
        int $initialTime = 0,
        ?SpawnPosition $configuredSpawn = null,
        ?GeneratorOptions $generatorOptions = null,
    ): OpenedWorld {
        $worldsPath = $this->ensureWorldsDirectory();
        $directoryName = self::safeDirectoryName($worldId);
        if (!$createIfMissing) {
            $directoryName = $this->resolveExistingDirectoryName($worldsPath, $directoryName);
        }
        $worldPath = $worldsPath . DIRECTORY_SEPARATOR . $directoryName;
        $this->assertContainedExistingPath($worldsPath, $worldPath);
        $exists = file_exists($worldPath);
        if ($exists && $failIfExists) {
            throw new RuntimeException("World '$worldId' already exists.");
        }
        if (!$exists && !$createIfMissing) {
            throw new RuntimeException("World '$worldId' does not exist.");
        }

        $internalStates = $this->internalStates($data);
        $persistentStates = $this->persistentStates($data);
        $configuredSpawn ??= $worldId === $config->levelName && $config->spawnX !== null ? new SpawnPosition(
            $config->spawnX,
            $config->spawnY ?? 64,
            $config->spawnZ ?? 0,
        ) : null;
        if ($exists) {
            $provider = $this->providers->open($worldPath, $internalStates, $persistentStates);
        } else {
            $seed ??= $config->levelSeed;
            $generatorName ??= $config->levelGenerator;
            $generatorOptions ??= new GeneratorOptions();
            $difficulty ??= self::difficulty($config->difficulty);
            $configuredGenerator = WorldGeneratorFactory::create(
                $generatorName,
                $seed,
                $internalStates,
                $generatorOptions,
                registry: $this->generators,
            );
            $defaultSpawn = $configuredSpawn ?? $configuredGenerator->defaultSpawn();
            $provider = $this->providers->create(
                $worldPath,
                new WorldData(
                    new WorldMetadata($displayName ?? $worldId, $seed),
                    $generatorName,
                    $defaultSpawn,
                    time: $initialTime,
                    difficulty: $difficulty,
                    generatorVersion: $configuredGenerator->version(),
                    generatorOptions: $generatorOptions->canonicalJson(),
                ),
                $internalStates,
                $persistentStates,
            );
        }

        try {
            return $this->compose($config, $provider, $internalStates, $configuredSpawn);
        } catch (Throwable $failure) {
            $provider->close();
            throw $failure;
        }
    }

    private function compose(
        ServerConfig $config,
        WritableWorldProvider $provider,
        BlockStateRegistry $internalStates,
        ?SpawnPosition $configuredSpawn,
        ?WorldPreparationResult $prepared = null,
    ): OpenedWorld {
        $overworld = $this->composeDimension(
            $config,
            $provider,
            $internalStates,
            WorldDimension::OVERWORLD,
            $configuredSpawn,
            $prepared,
        );
        $nether = $this->composeDimension(
            $config,
            $provider,
            $internalStates,
            WorldDimension::NETHER,
            null,
        );
        $end = $this->composeDimension(
            $config,
            $provider,
            $internalStates,
            WorldDimension::END,
            null,
        );

        return new OpenedWorld($overworld->world, $overworld->data, [$nether, $end]);
    }

    private function composeDimension(
        ServerConfig $config,
        WritableWorldProvider $provider,
        BlockStateRegistry $internalStates,
        WorldDimension $dimension,
        ?SpawnPosition $configuredSpawn,
        ?WorldPreparationResult $prepared = null,
    ): OpenedWorld {
        $stored = $provider->worldData();
        $generatorOptions = GeneratorOptions::fromJson($stored->generatorOptions);
        try {
            $identifier = WorldGeneratorFactory::canonicalIdentifier($stored->generatorName);
            $definition = $this->generators->require($identifier);
            $deferredGeneratorVersion = $prepared instanceof WorldPreparationResult
                ? $prepared->generatorVersion
                : $definition->version;
            $deferredSpawn = $prepared instanceof WorldPreparationResult
                ? $prepared->defaultSpawn
                : $stored->spawn;
            $generator = $prepared !== null || $definition->execution === GeneratorExecution::WORKER
                ? new DeferredWorldGenerator(
                    $stored->generatorName,
                    $deferredGeneratorVersion,
                    $deferredSpawn,
                    fn(): \Bedriox\Server\World\VersionedWorldGenerator => WorldGeneratorFactory::create(
                        $stored->generatorName,
                        $stored->metadata->seed,
                        $internalStates,
                        $generatorOptions,
                        $dimension->value,
                        registry: $this->generators,
                    ),
                )
                : WorldGeneratorFactory::create(
                    $stored->generatorName,
                    $stored->metadata->seed,
                    $internalStates,
                    $generatorOptions,
                    $dimension->value,
                    registry: $this->generators,
                );
        } catch (InvalidArgumentException $failure) {
            throw new RuntimeException(
                "World generator {$stored->generatorName} is not supported.",
                previous: $failure,
            );
        }
        $generatorVersion = $generator->version();
        if ($dimension === WorldDimension::OVERWORLD && $prepared !== null && (
            $prepared->generatorIdentifier !== $identifier
            || $prepared->generatorVersion !== $definition->version
            || $stored->spawn != $prepared->defaultSpawn && $configuredSpawn === null
        )) {
            throw new RuntimeException('Prepared world metadata does not match authoritative storage metadata.');
        }
        if ($stored->bedrioxGeneratorVersionDeclared && $stored->generatorVersion !== $generatorVersion) {
            throw new RuntimeException(
                "World generator {$stored->generatorName} version {$stored->generatorVersion} is not supported; expected version $generatorVersion.",
            );
        }
        $world = new World(
            $stored->metadata,
            $generator,
            new ChunkRepository($config->chunkCacheLimit),
            $configuredSpawn ?? ($dimension === WorldDimension::OVERWORLD ? null : $generator->defaultSpawn()),
            provider: $provider,
            chunkUnloads: new ChunkUnloadManager(self::unloadGraceNanoseconds($config)),
            generatorOptions: $generatorOptions->canonicalJson(),
            dimension: $dimension,
        );
        $effectiveData = new WorldData(
            $stored->metadata,
            $stored->generatorName,
            $world->spawn(),
            $stored->time,
            $stored->difficulty,
            $generatorVersion,
            $generatorOptions->canonicalJson(),
        );

        return new OpenedWorld($world, $effectiveData);
    }

    private function ensureWorldsDirectory(): string
    {
        if (!is_dir($this->worldsPath) && !@mkdir($this->worldsPath, 0775, true) && !is_dir($this->worldsPath)) {
            throw new RuntimeException('Unable to create the worlds directory.');
        }
        $resolved = realpath($this->worldsPath);
        if (!is_string($resolved)) {
            throw new RuntimeException('Unable to resolve the worlds directory.');
        }

        return rtrim($resolved, "\\/");
    }

    private function assertContainedExistingPath(string $worldsPath, string $worldPath): void
    {
        if (!file_exists($worldPath)) {
            return;
        }
        $resolved = realpath($worldPath);
        $prefix = $worldsPath . DIRECTORY_SEPARATOR;
        if (!is_string($resolved) || !str_starts_with(strtolower($resolved . DIRECTORY_SEPARATOR), strtolower($prefix))) {
            throw new RuntimeException('Configured world path must remain inside the worlds directory.');
        }
        if (!is_dir($resolved)) {
            throw new RuntimeException('Configured world path is not a directory.');
        }
    }

    private function resolveExistingDirectoryName(string $worldsPath, string $requested): string
    {
        $matches = [];
        foreach (scandir($worldsPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || strcasecmp($entry, $requested) !== 0) {
                continue;
            }
            if (is_dir($worldsPath . DIRECTORY_SEPARATOR . $entry)) {
                $matches[] = $entry;
            }
        }
        if (count($matches) > 1) {
            throw new RuntimeException("World '$requested' is ambiguous on this filesystem.");
        }

        return $matches[0] ?? $requested;
    }

    private static function safeDirectoryName(string $levelName): string
    {
        if (
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._ -]{0,63}\z/D', $levelName) !== 1
            || $levelName === '.'
            || $levelName === '..'
            || str_ends_with($levelName, '.')
            || str_ends_with($levelName, ' ')
        ) {
            throw new InvalidArgumentException('Level name is not a safe world directory name.');
        }

        return $levelName;
    }

    private static function difficulty(string $difficulty): int
    {
        return match ($difficulty) {
            'peaceful' => 0,
            'easy' => 1,
            'normal' => 2,
            'hard' => 3,
            default => throw new InvalidArgumentException('Difficulty is unsupported.'),
        };
    }

    private static function apiDifficulty(WorldDifficulty $difficulty): int
    {
        return match ($difficulty) {
            WorldDifficulty::PEACEFUL => 0,
            WorldDifficulty::EASY => 1,
            WorldDifficulty::NORMAL => 2,
            WorldDifficulty::HARD => 3,
        };
    }

    private static function unloadGraceNanoseconds(ServerConfig $config): int
    {
        return intdiv($config->chunkUnloadGraceTicks * 1_000_000_000, $config->ticksPerSecond);
    }
}
