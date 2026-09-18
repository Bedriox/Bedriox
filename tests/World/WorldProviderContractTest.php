<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Provider\Exception\UnsupportedWorldFormatException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldProviderException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Bedriox\Server\World\Provider\LoadedChunkData;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class WorldProviderContractTest extends TestCase
{
    public function testProviderDistinguishesMissingChunksFromFailuresAndClosesIdempotently(): void
    {
        $worldData = new WorldData(new WorldMetadata('world', 42), 'flat', new SpawnPosition(0, 64, 0));
        $provider = new InMemoryContractProvider($worldData);
        $position = new ChunkPosition(-1, -2);

        self::assertNull($provider->loadChunk($position));
        self::assertSame($worldData, $provider->worldData());

        $chunk = (new Chunk($position, new InternalBlockStateId(0), []))
            ->withFinalizationState(\Bedriox\Server\World\ChunkFinalizationState::NeedsPopulation);
        $provider->saveChunk(new ChunkSaveData($chunk));
        self::assertSame($chunk, $provider->loadChunk($position)?->chunk);

        $provider->close();
        $provider->close();
        $this->expectException(WorldProviderClosedException::class);
        $provider->loadChunk($position);
    }

    public function testFailureCategoriesAreDistinctProviderFailures(): void
    {
        foreach ([
            new CorruptChunkException(),
            new CorruptWorldDataException(),
            new UnsupportedWorldFormatException(),
            new WorldStorageException(),
            new WorldProviderClosedException(),
        ] as $failure) {
            self::assertInstanceOf(WorldProviderException::class, $failure);
        }
        self::assertNotSame(CorruptChunkException::class, WorldStorageException::class);
        self::assertNotSame(UnsupportedWorldFormatException::class, CorruptChunkException::class);
    }
}

final class InMemoryContractProvider implements WritableWorldProvider
{
    /** @var array<string, LoadedChunkData> */
    private array $chunks = [];

    private bool $closed = false;

    public function __construct(private WorldData $data) {}

    public function worldData(): WorldData
    {
        $this->assertOpen();

        return $this->data;
    }

    public function loadChunk(ChunkPosition $position): ?LoadedChunkData
    {
        $this->assertOpen();

        return $this->chunks[$position->key()] ?? null;
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $this->assertOpen();
        $this->data = $worldData;
    }

    public function saveChunk(ChunkSaveData $chunkData): void
    {
        $this->assertOpen();
        $this->chunks[$chunkData->chunk->position->key()] = new LoadedChunkData($chunkData->chunk);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new WorldProviderClosedException('Provider is closed.');
        }
    }
}
