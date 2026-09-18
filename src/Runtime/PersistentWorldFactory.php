<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Provider\LevelDbWorldProviderFactory;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WorldProviderFactory;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Opens the configured Mojang LevelDB world before network admission begins. */
final readonly class PersistentWorldFactory implements ConfiguredWorldFactory
{
    private string $worldsPath;

    public function __construct(
        string $workingDirectory,
        private WorldProviderFactory $providers = new LevelDbWorldProviderFactory(),
    ) {
        if ($workingDirectory === '' || str_contains($workingDirectory, "\0")) {
            throw new InvalidArgumentException('Working directory must be a non-empty filesystem path.');
        }
        $this->worldsPath = rtrim($workingDirectory, "\\/") . DIRECTORY_SEPARATOR . 'worlds';
    }

    public function open(ServerConfig $config, BedrockDataSet $data): OpenedWorld
    {
        $directoryName = self::safeDirectoryName($config->levelName);
        $worldsPath = $this->ensureWorldsDirectory();
        $worldPath = $worldsPath . DIRECTORY_SEPARATOR . $directoryName;
        $this->assertContainedExistingPath($worldsPath, $worldPath);

        $networkStates = $data->blockStateRegistry();
        $internalStates = new BlockStateRegistry($networkStates->states());
        $persistentStates = $data->persistentBlockStateRegistry();
        $configuredSpawn = $config->spawnX === null ? null : new SpawnPosition(
            $config->spawnX,
            $config->spawnY ?? 64,
            $config->spawnZ ?? 0,
        );
        $defaultSpawn = $configuredSpawn ?? new SpawnPosition(0, 64, 0);

        $provider = file_exists($worldPath)
            ? $this->providers->open($worldPath, $internalStates, $persistentStates)
            : $this->providers->create(
                $worldPath,
                new WorldData(
                    new WorldMetadata($config->levelName, $config->levelSeed),
                    $config->levelGenerator,
                    $defaultSpawn,
                    difficulty: self::difficulty($config->difficulty),
                ),
                $internalStates,
                $persistentStates,
            );

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
    ): OpenedWorld {
        $stored = $provider->worldData();
        if ($stored->generatorName !== 'flat') {
            throw new RuntimeException(sprintf('World generator "%s" is not supported.', $stored->generatorName));
        }
        $palette = FixedFlatBlockPalette::fromRegistry($internalStates);
        $world = new World(
            $stored->metadata,
            new FlatWorldGenerator($palette),
            new ChunkRepository($config->chunkCacheLimit),
            $configuredSpawn,
            provider: $provider,
        );
        $effectiveData = new WorldData(
            $stored->metadata,
            $stored->generatorName,
            $world->spawn(),
            $stored->time,
            $stored->difficulty,
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
}
