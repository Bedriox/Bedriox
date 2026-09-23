<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Persistence\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\World\ProcessWorldProvider;
use Bedriox\Server\Persistence\World\WorldDataIpcCodec;
use Bedriox\Server\Persistence\World\WorldStorageStartup;
use Bedriox\Server\Persistence\World\WorldStorageStartupCodec;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\VanillaBlockStates;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldGeneratorFactory;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class ProcessWorldProviderTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/bedriox-world-storage-fixture';

    public function testWorldDataAndStartupCodecsRoundTripExactValues(): void
    {
        $data = self::worldData();
        $worldCodec = new WorldDataIpcCodec();
        self::assertEquals($data, $worldCodec->decode($worldCodec->encode($data)));

        $startup = new WorldStorageStartup('create', 'C:\\worlds\\test', $data, 1234);
        $startupCodec = new WorldStorageStartupCodec();
        self::assertEquals($startup, $startupCodec->decode($startupCodec->encode($startup)));
    }

    public function testDedicatedOwnerSavesAndLoadsCanonicalChunkAndClosesCleanly(): void
    {
        $states = self::states();
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-equivalence', self::worldData(), 1234),
            $states,
            entryPoint: self::FIXTURE,
        );
        $chunk = WorldGeneratorFactory::create('flat', 42, $states)->generate(new ChunkPosition(-2, 3));
        $provider->saveChunk(new ChunkSaveData($chunk));
        $loaded = $provider->loadChunk($chunk->position);

        self::assertNotNull($loaded);
        self::assertEquals($chunk->position, $loaded->chunk->position);
        self::assertSame($chunk->revision, $loaded->chunk->revision);
        self::assertSame($chunk->revision, $loaded->chunk->persistedRevision);
        self::assertFalse($loaded->upgraded);
        self::assertNull($provider->loadChunk(new ChunkPosition(100, 100)));

        $updated = new WorldData($provider->worldData()->metadata, 'flat', new SpawnPosition(8, 70, 9), 99, 3);
        $provider->saveWorldData($updated);
        self::assertEquals($updated, $provider->worldData());
        $provider->close();
        self::assertTrue($provider->ownerConfirmedClosed());

        $this->expectException(WorldProviderClosedException::class);
        $provider->worldData();
    }

    public function testCorruptChunkCategoryCrossesProcessBoundary(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-corrupt', self::worldData(), 1234),
            self::states(),
            entryPoint: self::FIXTURE,
        );
        try {
            $this->expectException(CorruptChunkException::class);
            $provider->loadChunk(new ChunkPosition(0, 0));
        } finally {
            $provider->close();
        }
    }

    public function testNonblockingSaveCoalescesRevisionsAndAsyncLoadDeduplicatesCoordinates(): void
    {
        $states = self::states();
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-async-slow-save', self::worldData(), 1234),
            $states,
            entryPoint: self::FIXTURE,
        );
        try {
            $chunk = WorldGeneratorFactory::create('flat', 42, $states)->generate(new ChunkPosition(4, -5));
            $revisionOne = $chunk->withBlockState(0, 64, 0, $states->internalId(VanillaBlockStates::air()));
            $revisionTwo = $revisionOne->withBlockState(1, 64, 0, $states->internalId(VanillaBlockStates::stone()));

            self::assertSame(PersistenceSubmission::ACCEPTED, $provider->enqueueChunkSave(new ChunkSaveData($revisionOne))->status);
            self::assertSame(PersistenceSubmission::COALESCED, $provider->enqueueChunkSave(new ChunkSaveData($revisionTwo))->status);
            $pollStarted = hrtime(true);
            self::assertSame([], $provider->pollChunkSaves());
            self::assertLessThan(250_000_000, hrtime(true) - $pollStarted, 'Chunk save polling blocked on child disk work.');
            $completions = $provider->drainChunkSaves(5_000);
            self::assertCount(1, $completions);
            self::assertTrue($completions[0]->successful);
            self::assertSame($revisionTwo->revision, $completions[0]->revision);
            self::assertEquals($chunk->position, ProcessWorldProvider::chunkPositionFor($completions[0]));

            self::assertTrue($provider->requestChunkLoad($chunk->position));
            self::assertFalse($provider->requestChunkLoad($chunk->position));
            $loads = [];
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $loads = $provider->pollChunkLoads();
                if ($loads !== []) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
            self::assertCount(1, $loads);
            self::assertNotNull($loads[0]->loaded);
            self::assertSame($revisionTwo->revision, $loads[0]->loaded->chunk->revision);
        } finally {
            $provider->close();
        }
    }

    public function testTimedOutRequestFailsOwnerWithoutSubstitutingMissingChunk(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-timeout', self::worldData(), 1234),
            self::states(),
            100,
            self::FIXTURE,
        );

        $this->expectException(WorldStorageException::class);
        $provider->loadChunk(new ChunkPosition(0, 0));
    }

    private static function worldData(): WorldData
    {
        return new WorldData(
            new WorldMetadata('Process Test', 42),
            'flat',
            new SpawnPosition(0, 64, 0),
            12,
            2,
            1,
        );
    }

    private static function states(): BlockStateRegistry
    {
        return new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
    }
}
