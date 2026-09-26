<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Storage\LevelDb;

use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\DormantEntityRecord;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceRecord;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Storage\LevelDb\BedrioxEntityKey;
use Bedriox\Server\World\Storage\LevelDb\LevelDbDatabase;
use Bedriox\Server\World\Storage\LevelDb\LevelDbEntityPersistenceStore;
use PHPUnit\Framework\TestCase;

final class LevelDbEntityPersistenceStoreTest extends TestCase
{
    private const string UUID = '123e4567-e89b-42d3-a456-426614174000';
    private const string OTHER_UUID = '123e4567-e89b-42d3-a456-426614174001';

    public function testSnapshotSurvivesStoreRestartWithExactDurableState(): void
    {
        $database = new MemoryLevelDbDatabase();
        $position = new ChunkPosition(2, -3);
        $snapshot = new EntityChunkSnapshot('world', $position, 7, [
            self::record(self::UUID, $position, 11),
        ]);
        (new LevelDbEntityPersistenceStore($database, EntityPersistenceCodec::vanilla(), 'world'))
            ->saveEntityChunk($snapshot);

        $reopened = new LevelDbEntityPersistenceStore($database, EntityPersistenceCodec::vanilla(), 'world');
        $loaded = $reopened->loadEntityChunk($position);

        self::assertEquals($snapshot, $loaded);
        self::assertSame(1, $database->writeBatches);
        $reopened->saveEntityChunk($snapshot);
        self::assertSame(1, $database->writeBatches, 'An exact revision retry must be idempotent.');
    }

    public function testOwnershipMovementReplacesBothChunksInOneAtomicBatch(): void
    {
        $database = new MemoryLevelDbDatabase();
        $store = new LevelDbEntityPersistenceStore($database, EntityPersistenceCodec::vanilla(), 'world');
        $source = new ChunkPosition(0, 0);
        $destination = new ChunkPosition(1, 0);
        $store->saveEntityChunk(new EntityChunkSnapshot('world', $source, 4, [
            self::record(self::UUID, $source, 8),
            self::record(self::OTHER_UUID, $source, 3),
        ]));
        $store->saveEntityChunk(new EntityChunkSnapshot('world', $destination, 6, []));
        $beforeTransferBatches = $database->writeBatches;

        $store->transferEntityOwnership(new EntityOwnershipTransfer(
            self::UUID,
            8,
            new EntityChunkSnapshot('world', $source, 5, [self::record(self::OTHER_UUID, $source, 3)]),
            new EntityChunkSnapshot('world', $destination, 7, [self::record(self::UUID, $destination, 9)]),
        ));

        self::assertSame($beforeTransferBatches + 1, $database->writeBatches);
        self::assertSame([self::OTHER_UUID], array_keys($store->loadEntityChunk($source)?->revisions() ?? []));
        self::assertSame([self::UUID], array_keys($store->loadEntityChunk($destination)?->revisions() ?? []));

        $this->expectException(EntityPersistenceConflictException::class);
        $store->transferEntityOwnership(new EntityOwnershipTransfer(
            self::UUID,
            8,
            new EntityChunkSnapshot('world', $source, 6, [self::record(self::OTHER_UUID, $source, 3)]),
            new EntityChunkSnapshot('world', $destination, 8, [self::record(self::UUID, $destination, 9)]),
        ));
    }

    public function testUnknownCustomRecordRemainsDormantAcrossLoadAndResave(): void
    {
        $database = new MemoryLevelDbDatabase();
        $position = new ChunkPosition(-4, 5);
        $writer = new LevelDbEntityPersistenceStore(
            $database,
            new EntityPersistenceCodec(['example:clockwork_cow']),
            'world',
        );
        $writer->saveEntityChunk(new EntityChunkSnapshot('world', $position, 1, [
            self::record(self::UUID, $position, 2, 'example:clockwork_cow', "\x00plugin-state\xff"),
        ]));

        $withoutPlugin = new LevelDbEntityPersistenceStore($database, EntityPersistenceCodec::vanilla(), 'world');
        $dormant = $withoutPlugin->loadEntityChunk($position)?->records()[0] ?? null;
        self::assertInstanceOf(DormantEntityRecord::class, $dormant);
        $withoutPlugin->saveEntityChunk(new EntityChunkSnapshot('world', $position, 2, [$dormant]));

        $restored = $writer->loadEntityChunk($position)?->records()[0] ?? null;
        self::assertInstanceOf(EntityPersistenceRecord::class, $restored);
        self::assertSame('example:clockwork_cow', $restored->typeIdentifier());
        self::assertSame("\x00plugin-state\xff", $restored->customData);
        self::assertSame(2, $restored->revision());
    }

    public function testCorruptSnapshotIsIsolatedFromTerrainKeysAndOtherEntityChunks(): void
    {
        $database = new MemoryLevelDbDatabase();
        $store = new LevelDbEntityPersistenceStore($database, EntityPersistenceCodec::vanilla(), 'world');
        $corruptPosition = new ChunkPosition(3, 3);
        $healthyPosition = new ChunkPosition(4, 3);
        $store->saveEntityChunk(new EntityChunkSnapshot('world', $corruptPosition, 1, [
            self::record(self::UUID, $corruptPosition, 1),
        ]));
        $store->saveEntityChunk(new EntityChunkSnapshot('world', $healthyPosition, 1, [
            self::record(self::OTHER_UUID, $healthyPosition, 1),
        ]));
        $terrainKey = "terrain-record";
        $database->records[$terrainKey] = 'untouched';
        $key = BedrioxEntityKey::chunk($corruptPosition->x, $corruptPosition->z);
        $database->records[$key][12] = chr(ord($database->records[$key][12]) ^ 1);

        try {
            $store->loadEntityChunk($corruptPosition);
            self::fail('Corrupt entity persistence was accepted.');
        } catch (CorruptEntityPersistenceException $error) {
            self::assertEquals($corruptPosition, $error->chunk);
        }

        self::assertSame('untouched', $database->records[$terrainKey]);
        self::assertSame(
            [self::OTHER_UUID => 1],
            $store->loadEntityChunk($healthyPosition)?->revisions(),
        );
    }

    private static function record(
        string $uuid,
        ChunkPosition $owner,
        int $revision,
        string $type = 'minecraft:cow',
        string $customData = '',
    ): EntityPersistenceRecord {
        return new EntityPersistenceRecord(
            $type,
            $uuid,
            'world',
            $owner,
            new Position($owner->x * 16 + 0.5, 64.0, $owner->z * 16 + 0.5),
            0.0,
            0.0,
            new EntityMotion(),
            10.0,
            20,
            true,
            null,
            [],
            1,
            $customData,
            $revision,
        );
    }
}

final class MemoryLevelDbDatabase implements LevelDbDatabase
{
    /** @var array<string, string> */
    public array $records = [];

    public int $writeBatches = 0;

    public function get(string $key): ?string
    {
        return $this->records[$key] ?? null;
    }

    public function writeBatch(array $puts, array $deletes): void
    {
        $replacement = $this->records;
        foreach ($puts as $key => $value) {
            $replacement[$key] = $value;
        }
        foreach ($deletes as $key) {
            unset($replacement[$key]);
        }
        $this->records = $replacement;
        ++$this->writeBatches;
    }

    public function close(): void {}
}
