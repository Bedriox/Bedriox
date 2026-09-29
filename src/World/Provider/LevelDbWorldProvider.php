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

namespace Bedriox\Server\World\Provider;

use Bedriox\Data\LittleEndianBlockStateNbtCodec;
use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResult;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkFinalizationState;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Provider\Exception\UnsupportedWorldFormatException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException as StorageCorruptWorldDataException;
use Bedriox\Server\World\Storage\Exception\UnsupportedWorldDataException;
use Bedriox\Server\World\Storage\Exception\WorldDataWriteException;
use Bedriox\Server\World\Storage\LevelDatStore;
use Bedriox\Server\World\Storage\LevelDb\Data3dCodec;
use Bedriox\Server\World\Storage\LevelDb\LevelDbChunkKey;
use Bedriox\Server\World\Storage\LevelDb\LevelDbDatabase;
use Bedriox\Server\World\Storage\LevelDb\LevelDbEntityPersistenceStore;
use Bedriox\Server\World\Storage\LevelDb\LevelDbIoException;
use Bedriox\Server\World\Storage\LevelDb\LevelDbStorageException;
use Bedriox\Server\World\Storage\LevelDb\NativeLevelDbDatabase;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;
use Bedriox\Server\World\Storage\LevelDb\PersistentChunkMapper;
use Bedriox\Server\World\Storage\LevelDb\PersistentSubChunkCodec;
use Bedriox\Server\World\Storage\LevelNameStore;
use Bedriox\Server\World\Storage\Nbt\LevelDatCodec;
use Bedriox\Server\World\Storage\Nbt\LevelDatMetadata;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use Bedriox\Server\World\WorldMetadata;
use InvalidArgumentException;
use Throwable;

/** Mojang-compatible overworld provider backed by the qualified Bedriox LevelDB runtime. */
final class LevelDbWorldProvider implements WritableWorldProvider, EntityPersistenceStore, TransientEntityPersistenceStore
{
    private const int CURRENT_CHUNK_VERSION = 42;
    private const int CURRENT_NETWORK_VERSION = 2193;
    private const string TRANSIENT_ENTITY_KEY_PREFIX = "bedriox:transient_entities:";

    private bool $closed = false;

    private WorldData $data;

    public function __construct(
        private readonly string $levelDatPath,
        private readonly LevelDbDatabase $database,
        private LevelDatMetadata $levelDat,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
        private readonly LevelDatStore $levelDatStore = new LevelDatStore(),
        private readonly LevelNameStore $levelNameStore = new LevelNameStore(),
    ) {
        $this->data = self::worldDataFromMetadata($levelDat);
        $stateCodec = new LittleEndianBlockStateNbtCodec($persistentBlockStates);
        $this->subChunks = new PersistentSubChunkCodec($stateCodec);
        $this->blockEntities = new PersistentBlockEntityCodec();
        $this->mapper = new PersistentChunkMapper($blockStates, $persistentBlockStates);
        $this->entities = new LevelDbEntityPersistenceStore(
            $database,
            EntityPersistenceCodec::vanilla(),
            $this->data->metadata->name,
        );
    }

    private readonly PersistentSubChunkCodec $subChunks;

    private readonly PersistentBlockEntityCodec $blockEntities;

    private readonly PersistentChunkMapper $mapper;

    private readonly LevelDbEntityPersistenceStore $entities;

    public function loadTransientEntities(string $namespace): ?string
    {
        $this->assertOpen();
        self::validateTransientNamespace($namespace);
        try {
            return $this->database->get(self::TRANSIENT_ENTITY_KEY_PREFIX . $namespace);
        } catch (LevelDbIoException|LevelDbStorageException $error) {
            throw new WorldStorageException('Transient entity state could not be loaded.', previous: $error);
        }
    }

    public function saveTransientEntities(string $namespace, ?string $payload): void
    {
        $this->assertOpen();
        self::validateTransientNamespace($namespace);
        $key = self::TRANSIENT_ENTITY_KEY_PREFIX . $namespace;
        try {
            $this->database->writeBatch($payload === null ? [] : [$key => $payload], $payload === null ? [$key] : []);
        } catch (LevelDbIoException|LevelDbStorageException $error) {
            throw new WorldStorageException('Transient entity state could not be saved.', previous: $error);
        }
    }

    private static function validateTransientNamespace(string $namespace): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/D', $namespace) !== 1) {
            throw new InvalidArgumentException('Transient entity namespace is invalid.');
        }
    }

    public static function open(
        string $worldPath,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
        ?LevelDatStore $levelDatStore = null,
        ?LevelNameStore $levelNameStore = null,
    ): self {
        self::assertNativeSupport();
        $store = $levelDatStore ?? new LevelDatStore();
        $nameStore = $levelNameStore ?? new LevelNameStore();
        $levelDatPath = $worldPath . DIRECTORY_SEPARATOR . 'level.dat';
        try {
            $metadata = $store->load($levelDatPath);
            $database = NativeLevelDbDatabase::open($worldPath . DIRECTORY_SEPARATOR . 'db', false);
        } catch (UnsupportedWorldDataException $error) {
            throw new UnsupportedWorldFormatException($error->getMessage(), previous: $error);
        } catch (StorageCorruptWorldDataException $error) {
            throw new CorruptWorldDataException($error->getMessage(), previous: $error);
        } catch (WorldDataWriteException|LevelDbIoException|LevelDbStorageException $error) {
            throw new WorldStorageException($error->getMessage(), previous: $error);
        }

        try {
            $provider = new self($levelDatPath, $database, $metadata, $blockStates, $persistentBlockStates, $store, $nameStore);
            $nameStore->synchronize($worldPath . DIRECTORY_SEPARATOR . 'levelname.txt', $metadata->levelName());

            return $provider;
        } catch (CorruptWorldDataException|UnsupportedWorldFormatException|WorldDataWriteException $error) {
            $database->close();
            if ($error instanceof WorldDataWriteException) {
                throw new WorldStorageException('Unable to synchronize levelname.txt.', previous: $error);
            }
            throw $error;
        }
    }

    public static function create(
        string $worldPath,
        WorldData $worldData,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
        ?LevelDatStore $levelDatStore = null,
        ?LevelNameStore $levelNameStore = null,
        ?int $createdAt = null,
    ): self {
        self::assertNativeSupport();
        if (file_exists($worldPath)) {
            throw new WorldStorageException('Refusing to create a world over an existing path.');
        }
        if (!@mkdir($worldPath, 0775, true) && !is_dir($worldPath)) {
            throw new WorldStorageException('Unable to create the world directory.');
        }
        $store = $levelDatStore ?? new LevelDatStore();
        $nameStore = $levelNameStore ?? new LevelNameStore();
        $metadata = self::newMetadata($worldData, $createdAt ?? time());
        $levelDatPath = $worldPath . DIRECTORY_SEPARATOR . 'level.dat';
        try {
            $database = NativeLevelDbDatabase::open($worldPath . DIRECTORY_SEPARATOR . 'db', true);
            $store->save($levelDatPath, $metadata);
            $nameStore->synchronize($worldPath . DIRECTORY_SEPARATOR . 'levelname.txt', $worldData->metadata->name);
        } catch (WorldDataWriteException|LevelDbIoException|LevelDbStorageException $error) {
            if (isset($database)) {
                $database->close();
            }
            throw new WorldStorageException('Unable to create the LevelDB world.', previous: $error);
        }

        return new self($levelDatPath, $database, $metadata, $blockStates, $persistentBlockStates, $store, $nameStore);
    }

    public function worldData(): WorldData
    {
        $this->assertOpen();

        return $this->data;
    }

    public function loadChunk(ChunkPosition $position): ?LoadedChunkData
    {
        $this->assertOpen();
        try {
            $versionKey = LevelDbChunkKey::version($position->x, $position->z);
            $version = $this->database->get($versionKey);
            if ($version === null) {
                if ($this->hasOrphanedChunkData($position)) {
                    throw new CorruptChunkException('Chunk records exist without the required version record.');
                }

                return null;
            }
            if (strlen($version) !== 1) {
                throw new CorruptChunkException('Chunk version record must contain exactly one byte.');
            }
            $chunkVersion = ord($version);
            if ($chunkVersion !== self::CURRENT_CHUNK_VERSION) {
                throw new UnsupportedWorldFormatException("Chunk format version $chunkVersion is not supported.");
            }

            $data3d = $this->database->get(LevelDbChunkKey::data3d($position->x, $position->z));
            if ($data3d === null) {
                throw new CorruptChunkException('Current chunk is missing its required Data3D record.');
            }
            $biomes = $this->mapper->runtimeBiomes((new Data3dCodec())->decode($data3d));

            $sections = [];
            $upgraded = false;
            for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= Chunk::MAX_SECTION_Y; ++$sectionY) {
                $record = $this->database->get(LevelDbChunkKey::subChunk($position->x, $position->z, $sectionY));
                if ($record === null) {
                    continue;
                }
                $stored = $this->subChunks->decode($record, $sectionY);
                $upgraded = $upgraded || $stored->sourceVersion !== PersistentSubChunkCodec::WRITE_VERSION;
                $sections[] = $this->mapper->runtimeSubChunk($stored);
            }

            $finalizationBytes = $this->database->get(LevelDbChunkKey::finalization($position->x, $position->z));
            if ($finalizationBytes === null || strlen($finalizationBytes) !== 1) {
                throw new CorruptChunkException('Current chunk is missing a valid finalization record.');
            }
            $finalization = ChunkFinalizationState::tryFrom(ord($finalizationBytes));
            if ($finalization === null) {
                throw new CorruptChunkException('Chunk finalization state is outside the supported range.');
            }

            $blockEntityBytes = $this->database->get(LevelDbChunkKey::blockEntities($position->x, $position->z));
            $blockEntities = $blockEntityBytes === null
                ? new BlockEntityCollection($position)
                : $this->blockEntities->decode($blockEntityBytes, $position);

            return new LoadedChunkData(
                $this->mapper->chunk($position, $sections, $biomes, $finalization, $blockEntities),
                $upgraded,
            );
        } catch (CorruptChunkException|UnsupportedWorldFormatException $error) {
            throw $error;
        } catch (LevelDbIoException $error) {
            throw new WorldStorageException('Unable to read the chunk from LevelDB.', previous: $error);
        } catch (LevelDbStorageException|InvalidArgumentException $error) {
            throw new CorruptChunkException('Chunk storage is malformed or cannot be represented safely.', previous: $error);
        } catch (Throwable $error) {
            throw new WorldStorageException('Unable to load the chunk from LevelDB.', previous: $error);
        }
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $this->assertOpen();
        $root = $this->levelDat->root;
        $root['LevelName'] = LittleEndianNbtTag::string($worldData->metadata->name);
        $root['RandomSeed'] = LittleEndianNbtTag::long($worldData->metadata->seed);
        $root['generatorName'] = LittleEndianNbtTag::string($worldData->generatorName);
        $root['Generator'] = LittleEndianNbtTag::int(self::legacyGeneratorId($worldData->generatorName));
        $root['BedrioxGeneratorVersion'] = LittleEndianNbtTag::int($worldData->generatorVersion);
        $root['generatorOptions'] = LittleEndianNbtTag::string($worldData->generatorOptions);
        $root['SpawnX'] = LittleEndianNbtTag::int($worldData->spawn->x);
        $root['SpawnY'] = LittleEndianNbtTag::int($worldData->spawn->y);
        $root['SpawnZ'] = LittleEndianNbtTag::int($worldData->spawn->z);
        $root['Time'] = LittleEndianNbtTag::long($worldData->time);
        $root['Difficulty'] = LittleEndianNbtTag::int($worldData->difficulty);
        $metadata = new LevelDatMetadata($this->levelDat->headerVersion, $root);
        try {
            $this->levelDatStore->save($this->levelDatPath, $metadata);
        } catch (WorldDataWriteException $error) {
            throw new WorldStorageException('Unable to atomically save level.dat.', previous: $error);
        }
        $this->levelDat = $metadata;
        $this->data = $worldData;
        try {
            $this->levelNameStore->synchronize(
                dirname($this->levelDatPath) . DIRECTORY_SEPARATOR . 'levelname.txt',
                $worldData->metadata->name,
            );
        } catch (WorldDataWriteException $error) {
            throw new WorldStorageException('level.dat was saved, but levelname.txt could not be synchronized.', previous: $error);
        }
    }

    public function saveChunk(ChunkSaveData $chunkData): void
    {
        $this->assertOpen();
        $chunk = $chunkData->chunk;
        $x = $chunk->position->x;
        $z = $chunk->position->z;
        try {
            $puts = [
                LevelDbChunkKey::version($x, $z) => chr(self::CURRENT_CHUNK_VERSION),
                LevelDbChunkKey::data3d($x, $z) => (new Data3dCodec())->encode($this->mapper->data3d($chunk)),
                LevelDbChunkKey::finalization($x, $z) => chr($chunk->finalizationState->value),
            ];
            $deletes = [];
            for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= Chunk::MAX_SECTION_Y; ++$sectionY) {
                $key = LevelDbChunkKey::subChunk($x, $z, $sectionY);
                $section = $chunk->section($sectionY);
                if ($section === null) {
                    $deletes[] = $key;
                } else {
                    $puts[$key] = $this->subChunks->encode($this->mapper->storedSubChunk($section));
                }
            }
            $blockEntityKey = LevelDbChunkKey::blockEntities($x, $z);
            if ($chunk->blockEntityCollection()->count() === 0) {
                $deletes[] = $blockEntityKey;
            } else {
                $puts[$blockEntityKey] = $this->blockEntities->encode($chunk->blockEntityCollection());
            }
            $this->database->writeBatch($puts, $deletes);
        } catch (LevelDbIoException|LevelDbStorageException|InvalidArgumentException $error) {
            throw new WorldStorageException('Unable to atomically save the chunk.', previous: $error);
        }
    }

    public function loadEntityChunk(ChunkPosition $position): ?EntityChunkSnapshot
    {
        $this->assertOpen();

        return $this->entities->loadEntityChunk($position);
    }

    public function saveEntityChunk(EntityChunkSnapshot $snapshot): void
    {
        $this->assertOpen();
        $this->entities->saveEntityChunk($snapshot);
    }

    public function transferEntityOwnership(EntityOwnershipTransfer $transfer): EntityOwnershipTransferResult
    {
        $this->assertOpen();

        return $this->entities->transferEntityOwnership($transfer);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->database->close();
        } catch (LevelDbIoException $error) {
            $this->closed = true;
            throw new WorldStorageException('Unable to close the world LevelDB database.', previous: $error);
        }
        $this->closed = true;
    }

    private function hasOrphanedChunkData(ChunkPosition $position): bool
    {
        if ($this->database->get(LevelDbChunkKey::data3d($position->x, $position->z)) !== null
            || $this->database->get(LevelDbChunkKey::finalization($position->x, $position->z)) !== null
            || $this->database->get(LevelDbChunkKey::blockEntities($position->x, $position->z)) !== null) {
            return true;
        }
        for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= Chunk::MAX_SECTION_Y; ++$sectionY) {
            if ($this->database->get(LevelDbChunkKey::subChunk($position->x, $position->z, $sectionY)) !== null) {
                return true;
            }
        }

        return false;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new WorldProviderClosedException('World provider is closed.');
        }
    }

    private static function assertNativeSupport(): void
    {
        if (!extension_loaded('leveldb')) {
            throw new UnsupportedWorldFormatException('The qualified Bedriox leveldb extension is required.');
        }
    }

    private static function worldDataFromMetadata(LevelDatMetadata $metadata): WorldData
    {
        try {
            return new WorldData(
                new WorldMetadata($metadata->levelName(), $metadata->seed()),
                $metadata->generatorName(),
                new SpawnPosition($metadata->spawnX(), $metadata->spawnY(), $metadata->spawnZ()),
                $metadata->time(),
                $metadata->difficulty(),
                $metadata->generatorVersion(),
                $metadata->generatorOptions() === '' ? '{}' : $metadata->generatorOptions(),
            );
        } catch (UnsupportedWorldDataException $error) {
            throw new UnsupportedWorldFormatException($error->getMessage(), previous: $error);
        } catch (StorageCorruptWorldDataException|\InvalidArgumentException $error) {
            throw new CorruptWorldDataException('level.dat contains invalid authoritative world metadata.', previous: $error);
        }
    }

    private static function newMetadata(WorldData $data, int $createdAt): LevelDatMetadata
    {
        if ($createdAt < 0) {
            throw new InvalidArgumentException('World creation time must be a non-negative Unix timestamp.');
        }

        return new LevelDatMetadata(LevelDatCodec::CURRENT_STORAGE_VERSION, [
            'StorageVersion' => LittleEndianNbtTag::int(LevelDatCodec::CURRENT_STORAGE_VERSION),
            'NetworkVersion' => LittleEndianNbtTag::int(self::CURRENT_NETWORK_VERSION),
            'LevelName' => LittleEndianNbtTag::string($data->metadata->name),
            'RandomSeed' => LittleEndianNbtTag::long($data->metadata->seed),
            'generatorName' => LittleEndianNbtTag::string($data->generatorName),
            'Generator' => LittleEndianNbtTag::int(self::legacyGeneratorId($data->generatorName)),
            'BedrioxGeneratorVersion' => LittleEndianNbtTag::int($data->generatorVersion),
            'generatorOptions' => LittleEndianNbtTag::string($data->generatorOptions),
            'GameType' => LittleEndianNbtTag::int(0),
            'LastPlayed' => LittleEndianNbtTag::long($createdAt),
            'DayCycleStopTime' => LittleEndianNbtTag::int(-1),
            'SpawnX' => LittleEndianNbtTag::int($data->spawn->x),
            'SpawnY' => LittleEndianNbtTag::int($data->spawn->y),
            'SpawnZ' => LittleEndianNbtTag::int($data->spawn->z),
            'Time' => LittleEndianNbtTag::long($data->time),
            'Difficulty' => LittleEndianNbtTag::int($data->difficulty),
            'commandsEnabled' => LittleEndianNbtTag::byte(0),
            'immutableWorld' => LittleEndianNbtTag::byte(0),
            'pvp' => LittleEndianNbtTag::byte(1),
            'spawnMobs' => LittleEndianNbtTag::byte(1),
            'texturePacksRequired' => LittleEndianNbtTag::byte(0),
            'lightningLevel' => LittleEndianNbtTag::float(0.0),
            'lightningTime' => LittleEndianNbtTag::int(0),
            'rainLevel' => LittleEndianNbtTag::float(0.0),
            'rainTime' => LittleEndianNbtTag::int(0),
            'lastOpenedWithVersion' => LittleEndianNbtTag::list(
                LittleEndianNbtTag::INT,
                array_map(LittleEndianNbtTag::int(...), self::gameVersionParts()),
            ),
        ]);
    }

    /** @return list<int> */
    private static function gameVersionParts(): array
    {
        $parts = explode('.', ProtocolVersion::GAME_VERSION);
        if (count($parts) !== 3) {
            throw new \LogicException('Bedrock game version must contain three numeric components.');
        }
        $version = [];
        foreach ($parts as $part) {
            if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $part) !== 1) {
                throw new \LogicException('Bedrock game version must contain canonical numeric components.');
            }
            $version[] = (int) $part;
        }
        $version[] = 0;
        $version[] = 0;

        return $version;
    }

    private static function legacyGeneratorId(string $generatorName): int
    {
        return match ($generatorName) {
            'default' => 1,
            'flat' => 2,
            default => 1,
        };
    }
}
