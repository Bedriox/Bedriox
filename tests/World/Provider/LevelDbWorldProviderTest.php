<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Provider;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\LittleEndianBlockStateNbtCodec;
use Bedriox\Data\OpaquePersistentBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Bedriox\Server\World\Provider\LevelDbWorldProvider;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\Storage\LevelDatStore;
use Bedriox\Server\World\Storage\LevelDb\LevelDbChunkKey;
use Bedriox\Server\World\Storage\LevelDb\LevelDbDatabase;
use Bedriox\Server\World\Storage\LevelDb\LevelDbIoException;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockStorage;
use Bedriox\Server\World\Storage\LevelDb\PersistentSubChunkCodec;
use Bedriox\Server\World\Storage\LevelDb\StoredSubChunk;
use Bedriox\Server\World\Storage\Nbt\LevelDatMetadata;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LevelDbWorldProviderTest extends TestCase
{
    public function testSaveUsesOneAtomicBatchAndRoundTripsCanonicalChunkState(): void
    {
        [$provider, $database, $registry, $persistentRegistry] = self::provider();
        $generator = new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($registry));
        $chunk = $generator->generate(new ChunkPosition(-1, -2));

        $provider->saveChunk(new ChunkSaveData($chunk));

        self::assertSame(1, $database->writeCount);
        self::assertSame(chr(42), $database->records[LevelDbChunkKey::version(-1, -2)]);
        self::assertArrayHasKey(LevelDbChunkKey::data3d(-1, -2), $database->records);
        self::assertArrayHasKey(LevelDbChunkKey::subChunk(-1, -2, 3), $database->records);
        self::assertArrayHasKey(LevelDbChunkKey::finalization(-1, -2), $database->records);

        $loaded = $provider->loadChunk(new ChunkPosition(-1, -2));
        self::assertNotNull($loaded);
        self::assertFalse($loaded->upgraded);
        self::assertSame(
            $chunk->blockStateAt(2, 63, 5)->value,
            $loaded->chunk->blockStateAt(2, 63, 5)->value,
        );
        self::assertSame('minecraft:plains', $loaded->chunk->biomeAt(2, 63, 5)->identifier);
        self::assertSame($chunk->finalizationState, $loaded->chunk->finalizationState);
    }

    public function testMissingChunkIsDistinctFromOrphanedAndMalformedChunkData(): void
    {
        [$provider, $database] = self::provider();
        $position = new ChunkPosition(4, -7);
        self::assertNull($provider->loadChunk($position));

        $database->records[LevelDbChunkKey::data3d(4, -7)] = 'orphan';
        try {
            $provider->loadChunk($position);
            self::fail('Orphaned chunk data was treated as a missing chunk.');
        } catch (CorruptChunkException $error) {
            self::assertStringContainsString('without the required version', $error->getMessage());
        }

        $database->records = [LevelDbChunkKey::version(4, -7) => chr(42)];
        $this->expectException(CorruptChunkException::class);
        $this->expectExceptionMessage('Data3D');
        $provider->loadChunk($position);
    }

    public function testFailedBatchIsReportedAndDoesNotPartiallyPublishRecords(): void
    {
        [$provider, $database, $registry] = self::provider();
        $database->failWrites = true;
        $chunk = (new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($registry)))
            ->generate(new ChunkPosition(8, 9));

        try {
            $provider->saveChunk(new ChunkSaveData($chunk));
            self::fail('Failed atomic batch was accepted.');
        } catch (WorldStorageException) {
            self::assertSame([], $database->records);
            self::assertSame(1, $database->writeCount);
        }
    }

    public function testDatabaseReadFailureIsNotMisclassifiedAsChunkCorruption(): void
    {
        [$provider, $database] = self::provider();
        $database->failReads = true;

        $this->expectException(WorldStorageException::class);
        $provider->loadChunk(new ChunkPosition(1, 2));
    }

    public function testValidButUnadmittedPersistentStateFailsExplicitlyInsteadOfBecomingAir(): void
    {
        [$provider, $database, $registry] = self::provider();
        $position = new ChunkPosition(2, 3);
        $chunk = (new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($registry)))->generate($position);
        $provider->saveChunk(new ChunkSaveData($chunk));

        $persistentRegistry = BedrockDataSet::bundled()->persistentBlockStateRegistry();
        $stateCodec = new LittleEndianBlockStateNbtCodec($persistentRegistry);
        $knownBytes = $stateCodec->encode($persistentRegistry->knownState(\Bedriox\Data\CanonicalBlockState::from('minecraft:air')));
        $unknownBytes = str_replace('minecraft:air', 'minecraft:bad', $knownBytes);
        self::assertNotSame($knownBytes, $unknownBytes);
        $opaque = OpaquePersistentBlockState::fromValidatedEncodedRoot($unknownBytes);
        $database->records[LevelDbChunkKey::subChunk(2, 3, 3)] = (new PersistentSubChunkCodec($stateCodec))->encode(
            new StoredSubChunk(3, [PersistentBlockStorage::uniform($opaque)]),
        );

        $this->expectException(CorruptChunkException::class);
        $this->expectExceptionMessage('cannot be represented safely');
        $provider->loadChunk($position);
    }

    public function testWorldDataSavePreservesOpaqueMetadataAndCloseIsIdempotent(): void
    {
        $directory = self::temporaryDirectory();
        $levelDatPath = $directory . DIRECTORY_SEPARATOR . 'level.dat';
        [$provider, $database] = self::provider($levelDatPath);
        try {
            $replacement = new WorldData(
                new WorldMetadata('Renamed World', 9876),
                'flat',
                new SpawnPosition(10, 70, -3),
                123,
            );
            $provider->saveWorldData($replacement);
            $saved = (new LevelDatStore())->load($levelDatPath);

            self::assertSame('Renamed World', $saved->levelName());
            self::assertSame(9876, $saved->seed());
            self::assertSame(123, $saved->time());
            self::assertSame('preserve-me', $saved->root['BedrioxOpaqueTest']->value);

            $provider->close();
            $provider->close();
            self::assertSame(1, $database->closeCount);
            $this->expectException(WorldProviderClosedException::class);
            $provider->worldData();
        } finally {
            self::removeDirectory($directory);
        }
    }

    /**
     * @return array{LevelDbWorldProvider, MemoryLevelDbDatabase, BlockStateRegistry,
     *     \Bedriox\Data\PersistentBlockStateRegistry}
     */
    private static function provider(?string $levelDatPath = null): array
    {
        $dataSet = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($dataSet->blockStateRegistry()->states());
        $database = new MemoryLevelDbDatabase();
        $metadata = self::metadata();
        $levelDatPath ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unused-level.dat';

        return [
            new LevelDbWorldProvider(
                $levelDatPath,
                $database,
                $metadata,
                $registry,
                $dataSet->persistentBlockStateRegistry(),
            ),
            $database,
            $registry,
            $dataSet->persistentBlockStateRegistry(),
        ];
    }

    private static function metadata(): LevelDatMetadata
    {
        return new LevelDatMetadata(10, [
            'StorageVersion' => LittleEndianNbtTag::int(10),
            'NetworkVersion' => LittleEndianNbtTag::int(2193),
            'LevelName' => LittleEndianNbtTag::string('World'),
            'RandomSeed' => LittleEndianNbtTag::long(42),
            'generatorName' => LittleEndianNbtTag::string('flat'),
            'generatorOptions' => LittleEndianNbtTag::string(''),
            'SpawnX' => LittleEndianNbtTag::int(0),
            'SpawnY' => LittleEndianNbtTag::int(64),
            'SpawnZ' => LittleEndianNbtTag::int(0),
            'Time' => LittleEndianNbtTag::long(0),
            'BedrioxOpaqueTest' => LittleEndianNbtTag::string('preserve-me'),
        ]);
    }

    private static function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-provider-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path));

        return $path;
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($path);
    }
}

final class MemoryLevelDbDatabase implements LevelDbDatabase
{
    /** @var array<string, string> */
    public array $records = [];

    public int $writeCount = 0;

    public int $closeCount = 0;

    public bool $failWrites = false;

    public bool $failReads = false;

    private bool $closed = false;

    public function get(string $key): ?string
    {
        if ($this->closed) {
            throw new RuntimeException('closed');
        }
        if ($this->failReads) {
            throw new LevelDbIoException('injected read failure');
        }

        return $this->records[$key] ?? null;
    }

    public function writeBatch(array $puts, array $deletes): void
    {
        ++$this->writeCount;
        if ($this->failWrites) {
            throw new \Bedriox\Server\World\Storage\LevelDb\LevelDbStorageException('injected failure');
        }
        $records = $this->records;
        foreach ($deletes as $key) {
            unset($records[$key]);
        }
        foreach ($puts as $key => $value) {
            $records[$key] = $value;
        }
        $this->records = $records;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ++$this->closeCount;
    }
}
