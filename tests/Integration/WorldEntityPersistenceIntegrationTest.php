<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Integration;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResult;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceManager;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\ChunkUnloadManager;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class WorldEntityPersistenceIntegrationTest extends TestCase
{
    public function testChunkActivationHydratesSavedEntityStateAfterAWorldRestart(): void
    {
        $store = new EntityIntegrationPersistenceStore();
        $definitions = EntityDefinitionRegistry::baseline();
        $chunk = new ChunkPosition(0, 0);
        $firstRegistry = new EntityRegistry();
        $firstWorld = self::world();
        $firstPersistence = new EntityPersistenceManager(
            'world',
            $firstRegistry,
            $definitions,
            $store,
        );
        $firstWorld->attachEntityPersistence($firstPersistence);
        $firstWorld->chunk($chunk);
        $entity = $firstRegistry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));
        self::assertInstanceOf(CowEntity::class, $entity);
        $uuid = $entity->getUniqueId();
        $firstPersistence->registerSpawned($entity);
        $entity->damage(3.0);
        $firstRegistry->move($entity->getRuntimeId(), 'world', new Position(2.5, 64.0, 3.5), 135.0, -20.0);
        self::assertSame(1, $firstPersistence->flushShutdown()->savedChunks);
        $firstWorld->close();

        $secondRegistry = new EntityRegistry();
        $secondWorld = self::world();
        $secondPersistence = new EntityPersistenceManager(
            'world',
            $secondRegistry,
            $definitions,
            $store,
        );
        $secondWorld->attachEntityPersistence($secondPersistence);
        self::assertSame(0, $secondRegistry->count());

        $secondWorld->chunk($chunk);
        $restored = $secondRegistry->getByUniqueId($uuid);
        self::assertInstanceOf(CowEntity::class, $restored);
        self::assertSame(7.0, $restored->getHealth());
        self::assertEquals(new Position(2.5, 64.0, 3.5), $restored->internalPosition());
        self::assertSame(135.0, $restored->getYaw());
        self::assertSame(-20.0, $restored->getPitch());
        self::assertTrue($secondPersistence->isChunkActivated($chunk));
        self::assertSame(1, $secondPersistence->activeEntityCount());
        self::assertSame(2, $store->loadCalls[$chunk->key()] ?? 0);
        $secondWorld->close();
    }

    public function testRecoverableOwnershipConflictDefersChunkEviction(): void
    {
        $store = new EntityIntegrationPersistenceStore();
        $registry = new EntityRegistry();
        $world = self::world(new ChunkUnloadManager(graceNanoseconds: 0));
        $persistence = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $world->attachEntityPersistence($persistence);
        $source = new ChunkPosition(0, 0);
        $world->chunk($source);
        $entity = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));
        $persistence->registerSpawned($entity);
        self::assertSame(1, $persistence->persistDirty(1)->savedChunks);
        $registry->move($entity->getRuntimeId(), 'world', new Position(17.5, 64.0, 1.5), 0.0, 0.0);

        $store->failOwnershipTransfers = true;
        $deferred = $world->processChunkUnloads(1, 10_000);
        self::assertSame(0, $deferred->evicted);
        self::assertSame(1, $deferred->remainingQueued);
        self::assertTrue($world->hasLoadedChunk($source));

        $store->failOwnershipTransfers = false;
        self::assertSame(1, $world->processChunkUnloads(1, 10_000)->evicted);
        self::assertFalse($world->hasLoadedChunk($source));
        $world->close();
    }

    private static function world(?ChunkUnloadManager $chunkUnloads = null): World
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);

        return new World(
            new WorldMetadata('world', 12345),
            new FlatWorldGenerator($palette),
            new ChunkRepository(8),
            chunkUnloads: $chunkUnloads,
        );
    }
}

final class EntityIntegrationPersistenceStore implements EntityPersistenceStore
{
    /** @var array<string, EntityChunkSnapshot> */
    private array $snapshots = [];

    /** @var array<string, int> */
    public array $loadCalls = [];

    public bool $failOwnershipTransfers = false;

    public function loadEntityChunk(ChunkPosition $position): ?EntityChunkSnapshot
    {
        $key = $position->key();
        $this->loadCalls[$key] = ($this->loadCalls[$key] ?? 0) + 1;

        return $this->snapshots[$key] ?? null;
    }

    public function saveEntityChunk(EntityChunkSnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->chunk->key()] = $snapshot;
    }

    public function transferEntityOwnership(EntityOwnershipTransfer $transfer): EntityOwnershipTransferResult
    {
        if ($this->failOwnershipTransfers) {
            throw new EntityPersistenceConflictException('Injected recoverable ownership conflict.');
        }
        $this->snapshots[$transfer->sourceAfter->chunk->key()] = $transfer->sourceAfter;
        $this->snapshots[$transfer->destinationAfter->chunk->key()] = $transfer->destinationAfter;

        return new EntityOwnershipTransferResult($transfer->sourceAfter, $transfer->destinationAfter);
    }
}
