<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Runtime\PersistentWorldFactory;
use Bedriox\Server\Runtime\ServerConfig;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\LoadedChunkData;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WorldProviderFactory;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class PersistentWorldFactoryTest extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-world-open-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->workingDirectory));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->workingDirectory);
    }

    public function testCreatesMissingWorldUnderSafeWorldsDirectory(): void
    {
        $providers = new RecordingWorldProviderFactory();
        $opened = (new PersistentWorldFactory($this->workingDirectory, $providers))->open(
            self::config(levelName: 'Fresh World', levelSeed: 91, difficulty: 'hard'),
            BedrockDataSet::bundled(),
        );

        self::assertSame(
            $this->workingDirectory . DIRECTORY_SEPARATOR . 'worlds' . DIRECTORY_SEPARATOR . 'Fresh World',
            $providers->createdPath,
        );
        self::assertSame('Fresh World', $opened->data->metadata->name);
        self::assertSame(91, $opened->data->metadata->seed);
        self::assertSame(3, $opened->data->difficulty);
        self::assertSame([0, 64, 0], [$opened->world->spawn()->x, $opened->world->spawn()->y, $opened->world->spawn()->z]);
        $opened->world->close();
        self::assertTrue($providers->provider?->closed);
    }

    public function testExistingWorldMetadataIsAuthoritative(): void
    {
        $path = $this->workingDirectory . DIRECTORY_SEPARATOR . 'worlds' . DIRECTORY_SEPARATOR . 'world';
        self::assertTrue(mkdir($path, 0775, true));
        $providers = new RecordingWorldProviderFactory(new WorldData(
            new WorldMetadata('Stored Display Name', 8128),
            'flat',
            new SpawnPosition(17, 72, -4),
            22_000,
        ));

        $opened = (new PersistentWorldFactory($this->workingDirectory, $providers))->open(
            self::config(levelName: 'world', levelSeed: 1),
            BedrockDataSet::bundled(),
        );

        self::assertSame($path, $providers->openedPath);
        self::assertNull($providers->createdPath);
        self::assertSame('Stored Display Name', $opened->data->metadata->name);
        self::assertSame(8128, $opened->data->metadata->seed);
        self::assertSame(22_000, $opened->data->time);
        self::assertSame(2, $opened->data->difficulty);
        self::assertSame([17, 72, -4], [$opened->data->spawn->x, $opened->data->spawn->y, $opened->data->spawn->z]);
    }

    public function testExplicitSpawnOverrideReplacesStoredSpawnDeliberately(): void
    {
        $path = $this->workingDirectory . DIRECTORY_SEPARATOR . 'worlds' . DIRECTORY_SEPARATOR . 'world';
        self::assertTrue(mkdir($path, 0775, true));
        $providers = new RecordingWorldProviderFactory(new WorldData(
            new WorldMetadata('Stored World', 8),
            'flat',
            new SpawnPosition(1, 65, 2),
        ));

        $opened = (new PersistentWorldFactory($this->workingDirectory, $providers))->open(
            self::config(levelName: 'world', levelSeed: 99, spawn: new SpawnPosition(-9, 80, 14)),
            BedrockDataSet::bundled(),
        );

        self::assertSame('Stored World', $opened->data->metadata->name);
        self::assertSame(8, $opened->data->metadata->seed);
        self::assertSame([-9, 80, 14], [$opened->data->spawn->x, $opened->data->spawn->y, $opened->data->spawn->z]);
    }

    public function testCompositionFailureClosesOpenedProvider(): void
    {
        $path = $this->workingDirectory . DIRECTORY_SEPARATOR . 'worlds' . DIRECTORY_SEPARATOR . 'world';
        self::assertTrue(mkdir($path, 0775, true));
        $providers = new RecordingWorldProviderFactory(new WorldData(
            new WorldMetadata('Unsupported', 1),
            'future_generator',
            new SpawnPosition(0, 64, 0),
        ));

        try {
            (new PersistentWorldFactory($this->workingDirectory, $providers))->open(
                self::config(),
                BedrockDataSet::bundled(),
            );
            self::fail('Unsupported persisted generator was accepted.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('not supported', $failure->getMessage());
            self::assertTrue($providers->provider?->closed);
        }
    }

    public function testUnsafeLevelNameFailsBeforeProviderAccess(): void
    {
        $providers = new RecordingWorldProviderFactory();

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new PersistentWorldFactory($this->workingDirectory, $providers))->open(
                self::config(levelName: '..'),
                BedrockDataSet::bundled(),
            );
        } finally {
            self::assertNull($providers->openedPath);
            self::assertNull($providers->createdPath);
        }
    }

    private static function config(
        string $levelName = 'world',
        int $levelSeed = 0,
        ?SpawnPosition $spawn = null,
        string $difficulty = 'normal',
    ): ServerConfig {
        return new ServerConfig(
            bindAddress: '127.0.0.1',
            port: 19_132,
            serverName: 'Test',
            maximumPlayers: 1,
            authenticationMode: AuthenticationMode::SELF_SIGNED,
            levelName: $levelName,
            levelSeed: $levelSeed,
            difficulty: $difficulty,
            chunkCacheLimit: 81,
            spawnX: $spawn?->x,
            spawnY: $spawn?->y,
            spawnZ: $spawn?->z,
        );
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                throw new \RuntimeException('Temporary directory iterator returned an invalid entry.');
            }
            $entryPath = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                self::removeDirectory($entryPath);
            } else {
                unlink($entryPath);
            }
        }
        rmdir($path);
    }
}

final class RecordingWorldProviderFactory implements WorldProviderFactory
{
    public ?string $openedPath = null;

    public ?string $createdPath = null;

    public ?RecordingWritableWorldProvider $provider = null;

    public function __construct(private readonly ?WorldData $existingData = null) {}

    public function open(
        string $worldPath,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
    ): WritableWorldProvider {
        $this->openedPath = $worldPath;

        return $this->provider = new RecordingWritableWorldProvider(
            $this->existingData ?? throw new \LogicException('No existing world data was configured.'),
        );
    }

    public function create(
        string $worldPath,
        WorldData $worldData,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
    ): WritableWorldProvider {
        $this->createdPath = $worldPath;

        return $this->provider = new RecordingWritableWorldProvider($worldData);
    }
}

final class RecordingWritableWorldProvider implements WritableWorldProvider
{
    public bool $closed = false;

    public function __construct(private WorldData $data) {}

    public function worldData(): WorldData
    {
        return $this->data;
    }

    public function loadChunk(ChunkPosition $position): ?LoadedChunkData
    {
        return null;
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $this->data = $worldData;
    }

    public function saveChunk(ChunkSaveData $chunkData): void {}

    public function close(): void
    {
        $this->closed = true;
    }
}
