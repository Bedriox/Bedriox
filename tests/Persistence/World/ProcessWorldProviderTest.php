<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Persistence\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityPersistenceRecord;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\World\ProcessWorldProvider;
use Bedriox\Server\Persistence\World\WorldDataIpcCodec;
use Bedriox\Server\Persistence\World\WorldStorageStartup;
use Bedriox\Server\Persistence\World\WorldStorageStartupCodec;
use Bedriox\Server\Simulation\Position;
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

    public function testEntitySnapshotsAndAtomicOwnershipTransferCrossProcessBoundary(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-entities', self::worldData(), 1234),
            self::states(),
            entryPoint: self::FIXTURE,
        );
        $source = new ChunkPosition(0, 0);
        $destination = new ChunkPosition(1, 0);
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        try {
            self::assertNull($provider->loadEntityChunk($source));
            $provider->saveEntityChunk(new EntityChunkSnapshot('Process Test', $source, 1, [
                self::entityRecord($uuid, $source, 3),
            ]));
            $provider->saveEntityChunk(new EntityChunkSnapshot('Process Test', $destination, 1, []));
            self::assertSame([$uuid => 3], $provider->loadEntityChunk($source)?->revisions());

            $provider->transferEntityOwnership(new EntityOwnershipTransfer(
                $uuid,
                3,
                new EntityChunkSnapshot('Process Test', $source, 2, []),
                new EntityChunkSnapshot('Process Test', $destination, 2, [
                    self::entityRecord($uuid, $destination, 4),
                ]),
            ));

            self::assertSame([], $provider->loadEntityChunk($source)?->records());
            self::assertSame([$uuid => 4], $provider->loadEntityChunk($destination)?->revisions());
        } finally {
            $provider->close();
        }
    }

    public function testTerrainLoadPreloadsItsEntityOwnerSnapshot(): void
    {
        $states = self::states();
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-entity-bundle', self::worldData(), 1234),
            $states,
            entryPoint: self::FIXTURE,
        );
        $position = new ChunkPosition(2, -3);
        $uuid = '123e4567-e89b-42d3-a456-426614174001';
        try {
            $provider->saveChunk(new ChunkSaveData(WorldGeneratorFactory::create('flat', 42, $states)->generate($position)));
            $provider->saveEntityChunk(new EntityChunkSnapshot('Process Test', $position, 1, [
                self::entityRecord($uuid, $position, 7),
            ]));

            self::assertNotNull($provider->loadChunk($position));
            self::assertSame([$uuid => 7], $provider->loadEntityChunk($position)?->revisions());
        } finally {
            $provider->close();
        }
    }

    public function testEntityPersistenceDoesNotDrainQueuedTerrainLoads(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-timeout-entity-barrier', self::worldData(), 1234),
            self::states(),
            2_000,
            self::FIXTURE,
        );
        $queued = new ChunkPosition(40, 40);
        $entityChunk = new ChunkPosition(0, 0);
        try {
            self::assertTrue($provider->requestChunkLoad($queued));

            $started = hrtime(true);
            $provider->saveEntityChunk(new EntityChunkSnapshot('Process Test', $entityChunk, 1, []));
            self::assertLessThan(
                250_000_000,
                hrtime(true) - $started,
                'Entity persistence drained terrain work which had not started.',
            );

            self::assertSame([], $provider->pollChunkLoads());
            $loads = [];
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $loads = $provider->pollChunkLoads();
                if ($loads !== []) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
            self::assertCount(1, $loads);
            self::assertEquals($queued, $loads[0]->position);
        } finally {
            $provider->close();
        }
    }

    public function testCorruptEntitySnapshotDoesNotTerminateStorageOwner(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-entity-corrupt', self::worldData(), 1234),
            self::states(),
            entryPoint: self::FIXTURE,
        );
        try {
            try {
                $provider->loadEntityChunk(new ChunkPosition(0, 0));
                self::fail('Corrupt entity persistence was accepted.');
            } catch (CorruptEntityPersistenceException $error) {
                self::assertEquals(new ChunkPosition(0, 0), $error->chunk);
            }
            self::assertNull($provider->loadEntityChunk(new ChunkPosition(1, 0)));
            self::assertNull($provider->loadChunk(new ChunkPosition(1, 0)));
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

    private static function entityRecord(
        string $uuid,
        ChunkPosition $owner,
        int $revision,
    ): EntityPersistenceRecord {
        return new EntityPersistenceRecord(
            'minecraft:cow',
            $uuid,
            'Process Test',
            $owner,
            new Position($owner->x * 16 + 0.5, 64.0, $owner->z * 16 + 0.5),
            0.0,
            0.0,
            new EntityMotion(),
            10.0,
            0,
            true,
            null,
            [],
            0,
            '',
            $revision,
        );
    }
}
