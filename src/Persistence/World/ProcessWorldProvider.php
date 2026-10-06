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

namespace Bedriox\Server\Persistence\World;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\Persistence\AsynchronousEntityPersistenceStore;
use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\EntityChunkSaveCompletion;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferCodec;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferCompletion;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResult;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResultCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use Bedriox\Server\Persistence\OrderedPersistenceQueue;
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Persistence\PersistenceQueueStatusProvider;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\PersistenceWriteRequest;
use Bedriox\Server\Persistence\World\Internal\WorldStorageProcessProgram;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Internal\ProcessEnvironment;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Bedriox\Server\Worker\Task\SpawnWorldStorageOwnerRequest;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\AsynchronousWorldDataProvider;
use Bedriox\Server\World\Provider\AsynchronousWorldProvider;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Provider\Exception\UnsupportedWorldFormatException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldProviderException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Bedriox\Server\World\Provider\LoadedChunkData;
use Bedriox\Server\World\Provider\WorldData;
use RuntimeException;
use Throwable;

final class ProcessWorldProvider implements AsynchronousWorldProvider, AsynchronousWorldDataProvider, AsynchronousEntityPersistenceStore, TransientEntityPersistenceStore, PersistenceQueueStatusProvider
{
    private const int MAXIMUM_PRELOADED_ENTITY_CHUNKS = 2_048;
    private const int MAXIMUM_QUEUED_ENTITY_TRANSFERS = 256;
    private const int MAXIMUM_TRANSIENT_ENTITY_BYTES = 1_048_576;

    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $connection = null;
    /** @var resource|null */
    private $startupListener = null;
    private string $startupToken = '';
    private string $startupReceivedToken = '';
    private string $startupOutgoing = '';
    private string $startupNonce = '';
    private int $startupDeadlineNanoseconds = 0;
    private bool $startupReady = false;
    private bool $externallyLaunched = false;
    private ?string $externalLaunchFailure = null;
    private readonly string $epoch;
    private readonly WorkerFrameCodec $frames;
    private readonly ChunkTransferCodec $chunks;
    private readonly WorldDataIpcCodec $worldDataCodec;
    private readonly EntityPersistenceCodec $entityPersistenceCodec;
    private readonly EntityOwnershipTransferCodec $entityOwnershipTransfers;
    private readonly EntityOwnershipTransferResultCodec $entityOwnershipTransferResults;
    private readonly WorldChunkLoadPayloadCodec $chunkLoadPayloads;
    private readonly OrderedPersistenceQueue $writeQueue;
    private readonly OrderedPersistenceQueue $entityWriteQueue;
    private readonly OrderedPersistenceQueue $worldDataWriteQueue;
    private readonly OrderedPersistenceQueue $transientEntityWriteQueue;
    private readonly WorkerFrameDecoder $asyncDecoder;
    private int $nextTaskId = 1;
    private ?PersistenceWriteRequest $asyncWrite = null;
    private bool $asyncWriteIsEntity = false;
    private bool $asyncWriteIsWorldData = false;
    private bool $asyncWriteIsTransientEntity = false;
    private int $asyncTaskId = 0;
    private string $asyncOutgoing = '';
    /** @var array<string, array{position: ChunkPosition, dimension: WorldDimension}> */
    private array $queuedLoads = [];
    /** @var array<string, true> */
    private array $loadKeys = [];
    private ?ChunkPosition $asyncLoad = null;
    private WorldDimension $asyncLoadDimension = WorldDimension::OVERWORLD;
    /** @var array<string, array{transfer: EntityOwnershipTransfer, dimension: WorldDimension}> */
    private array $queuedEntityTransfers = [];
    private ?EntityOwnershipTransfer $asyncEntityTransfer = null;
    private WorldDimension $asyncEntityTransferDimension = WorldDimension::OVERWORLD;
    /** @var list<EntityOwnershipTransferCompletion> */
    private array $entityTransferCompletions = [];
    /** @var array<string, array{state: 'loaded'|'missing'|'corrupt', snapshot: ?EntityChunkSnapshot}> */
    private array $preloadedEntityChunks = [];
    /** @var list<ChunkLoadCompletion> */
    private array $loadCompletions = [];
    /** @var list<PersistenceWriteCompletion> */
    private array $deferredCompletions = [];
    /** @var list<EntityChunkSaveCompletion> */
    private array $deferredEntityCompletions = [];
    /** @var list<PersistenceWriteCompletion> */
    private array $deferredWorldDataCompletions = [];
    /** @var array<int, string> persistence request ID => bounded storage failure detail */
    private array $entityWriteFailureDetails = [];
    private bool $closed = false;
    private bool $ownerConfirmedClosed = false;
    private WorldData $data;
    private int $worldDataRevision = 0;
    /** @var array<string, int> */
    private array $transientEntityRevisions = [];
    /** @var array<string, ?string> */
    private array $transientEntityCache = [];

    private function __construct(
        private readonly string $applicationVersion,
        private readonly WorldStorageStartup $startup,
        private readonly BlockStateRegistry $blockStates,
        private readonly int $requestTimeoutMilliseconds,
        private readonly string $entryPoint,
        private readonly ?WorkerDispatcher $processLauncher = null,
        private readonly int $processLauncherTaskType = 0,
        ?EntityPersistenceCodec $entityPersistenceCodec = null,
    ) {
        if ($applicationVersion === '' || strlen($applicationVersion) > 128
            || $requestTimeoutMilliseconds < 100 || $requestTimeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('Process world provider configuration is invalid.');
        }
        $this->epoch = random_bytes(16);
        $this->frames = new WorkerFrameCodec();
        $this->chunks = new ChunkTransferCodec();
        $this->worldDataCodec = new WorldDataIpcCodec();
        $this->entityPersistenceCodec = $entityPersistenceCodec ?? EntityPersistenceCodec::vanilla();
        $this->entityOwnershipTransfers = new EntityOwnershipTransferCodec($this->entityPersistenceCodec);
        $this->entityOwnershipTransferResults = new EntityOwnershipTransferResultCodec($this->entityPersistenceCodec);
        $this->chunkLoadPayloads = new WorldChunkLoadPayloadCodec();
        $this->writeQueue = new OrderedPersistenceQueue(
            1_024,
            67_108_864,
            ChunkTransferCodec::MAXIMUM_ENCODED_BYTES + 1,
            1_024,
        );
        $this->entityWriteQueue = new OrderedPersistenceQueue(
            1_024,
            16_777_216,
            EntityPersistenceLimits::MAX_DOCUMENT_BYTES + 1,
            1_024,
        );
        $this->worldDataWriteQueue = new OrderedPersistenceQueue(2, 2_097_152, 1_048_576, 16);
        $this->transientEntityWriteQueue = new OrderedPersistenceQueue(
            64,
            8_388_608,
            self::MAXIMUM_TRANSIENT_ENTITY_BYTES + 66,
            64,
        );
        $this->asyncDecoder = new WorkerFrameDecoder($this->frames, 33_619_968);
    }

    public static function start(
        string $applicationVersion,
        WorldStorageStartup $startup,
        BlockStateRegistry $blockStates,
        int $requestTimeoutMilliseconds = 30_000,
        ?string $entryPoint = null,
        ?WorkerDispatcher $processLauncher = null,
        int $processLauncherTaskType = 0,
        ?EntityPersistenceCodec $entityPersistenceCodec = null,
    ): self {
        $provider = self::beginStart(
            $applicationVersion,
            $startup,
            $blockStates,
            $requestTimeoutMilliseconds,
            $entryPoint,
            $processLauncher,
            $processLauncherTaskType,
            $entityPersistenceCodec,
        );
        while (!$provider->pollStartup()) {
            usleep(1_000);
        }

        return $provider;
    }

    /**
     * Starts the dedicated storage owner without waiting for its LevelDB open/create work.
     * Call pollStartup() from the authoritative loop and publish the provider only after it returns true.
     */
    public static function beginStart(
        string $applicationVersion,
        WorldStorageStartup $startup,
        BlockStateRegistry $blockStates,
        int $requestTimeoutMilliseconds = 30_000,
        ?string $entryPoint = null,
        ?WorkerDispatcher $processLauncher = null,
        int $processLauncherTaskType = 0,
        ?EntityPersistenceCodec $entityPersistenceCodec = null,
    ): self {
        $provider = new self(
            $applicationVersion,
            $startup,
            $blockStates,
            $requestTimeoutMilliseconds,
            $entryPoint ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'bedriox-io.php',
            $processLauncher,
            $processLauncherTaskType,
            $entityPersistenceCodec,
        );
        $provider->beginLaunch();

        return $provider;
    }

    /** @phpstan-impure Advances the bounded nonblocking startup handshake. */
    public function pollStartup(): bool
    {
        if ($this->startupReady) {
            return true;
        }
        if ($this->closed) {
            throw new WorldProviderClosedException('World provider startup is closed.');
        }
        if (hrtime(true) >= $this->startupDeadlineNanoseconds) {
            throw $this->failOwner('Timed out starting the world storage process.');
        }
        if (!is_resource($this->connection)) {
            if (!is_resource($this->startupListener)) {
                throw $this->failOwner('World storage IPC listener is unavailable during startup.');
            }
            if (!$this->startupListenerReadable()) {
                $this->assertStartupProcessRunning();

                return false;
            }
            $connection = @stream_socket_accept($this->startupListener, 0);
            if (!is_resource($connection)) {
                $this->assertStartupProcessRunning();

                return false;
            }
            @fclose($this->startupListener);
            $this->startupListener = null;
            stream_set_blocking($connection, false);
            $this->connection = $connection;
        }

        if (strlen($this->startupReceivedToken) < strlen($this->startupToken)) {
            if (!$this->startupConnectionReadable()) {
                $this->assertStartupProcessRunning();

                return false;
            }
            $chunk = @fread(
                $this->connection,
                max(1, strlen($this->startupToken) - strlen($this->startupReceivedToken)),
            );
            if ($chunk === false) {
                throw $this->failOwner('World storage IPC authentication read failed.');
            }
            $this->startupReceivedToken .= $chunk;
            if (strlen($this->startupReceivedToken) < strlen($this->startupToken)) {
                $this->assertStartupProcessRunning();

                return false;
            }
            if (!hash_equals($this->startupToken, $this->startupReceivedToken)) {
                throw $this->failOwner('World storage IPC authentication failed.');
            }
            $this->startupOutgoing = $this->frames->encode(new WorkerFrame(
                WorkerFrameKind::HELLO,
                $this->epoch,
                metadata: WorldStorageProcessProgram::identity($this->applicationVersion, $this->startupNonce),
                payload: (new WorldStorageStartupCodec())->encode($this->startup),
            ));
        }

        if ($this->startupOutgoing !== '') {
            $written = @fwrite($this->connection, $this->startupOutgoing);
            if ($written === false) {
                throw $this->failOwner('World storage IPC handshake write failed.');
            }
            if ($written > 0) {
                $this->startupOutgoing = substr($this->startupOutgoing, $written);
            }
            if ($this->startupOutgoing !== '') {
                return false;
            }

            // The storage owner performs its potentially expensive open/create work
            // only after receiving HELLO. Never wait for READY in the same poll.
            return false;
        }

        if (!$this->startupConnectionReadable()) {
            $this->assertStartupProcessRunning();

            return false;
        }

        $bytes = @fread($this->connection, 262_144);
        if ($bytes === false) {
            throw $this->failOwner('World storage IPC handshake read failed.');
        }
        if ($bytes === '') {
            $this->assertStartupProcessRunning();

            return false;
        }
        try {
            $readyFrames = $this->asyncDecoder->push($bytes, 2);
        } catch (Throwable $error) {
            throw $this->failOwner('World storage process handshake framing failed.', $error);
        }
        if ($readyFrames === []) {
            return false;
        }
        if (count($readyFrames) !== 1) {
            throw $this->failOwner('World storage process returned an invalid startup frame count.');
        }
        $ready = $readyFrames[0];
        if (!hash_equals($this->epoch, $ready->epoch)) {
            throw $this->failOwner('World storage process handshake failed.');
        }
        if ($ready->kind === WorkerFrameKind::FAILURE) {
            $this->closed = true;
            $this->closeProcess();
            throw self::mappedFailure(
                is_string($ready->metadata['code'] ?? null) ? $ready->metadata['code'] : 'storage_failure',
                $ready->metadata,
            );
        }
        if ($ready->kind !== WorkerFrameKind::READY
            || $ready->metadata !== WorldStorageProcessProgram::identity($this->applicationVersion, $this->startupNonce)) {
            throw $this->failOwner('World storage process identity did not match.');
        }
        try {
            $this->data = $this->worldDataCodec->decode($ready->payload);
        } catch (Throwable $error) {
            throw $this->failOwner('World storage process returned invalid world data.', $error);
        }
        stream_set_timeout(
            $this->connection,
            intdiv($this->requestTimeoutMilliseconds, 1_000),
            ($this->requestTimeoutMilliseconds % 1_000) * 1_000,
        );
        $this->startupToken = '';
        $this->startupReceivedToken = '';
        $this->startupNonce = '';
        $this->startupReady = true;

        return true;
    }

    private function startupListenerReadable(): bool
    {
        if (!is_resource($this->startupListener)) {
            return false;
        }
        $read = [$this->startupListener];
        $write = [];
        $except = [];
        $selected = @stream_select($read, $write, $except, 0, 0);

        return is_int($selected) && $selected > 0;
    }

    private function startupConnectionReadable(): bool
    {
        if (!is_resource($this->connection)) {
            return false;
        }
        $read = [$this->connection];
        $write = [];
        $except = [];
        $selected = @stream_select($read, $write, $except, 0, 0);

        return is_int($selected) && $selected > 0;
    }

    public function worldData(): WorldData
    {
        $this->assertOpen();

        return $this->data;
    }

    public function persistenceQueueSnapshot(): PersistenceQueueSnapshot
    {
        $terrain = $this->writeQueue->snapshot();
        $entities = $this->entityWriteQueue->snapshot();
        $worldData = $this->worldDataWriteQueue->snapshot();
        $transientEntities = $this->transientEntityWriteQueue->snapshot();

        return new PersistenceQueueSnapshot(
            $terrain->queued + $entities->queued + $worldData->queued + $transientEntities->queued,
            $terrain->inFlight + $entities->inFlight + $worldData->inFlight + $transientEntities->inFlight,
            $terrain->completions + $entities->completions + $worldData->completions + $transientEntities->completions,
            $terrain->requestBytes + $entities->requestBytes + $worldData->requestBytes + $transientEntities->requestBytes,
            $terrain->coalesced + $entities->coalesced + $worldData->coalesced + $transientEntities->coalesced,
            $terrain->saturated + $entities->saturated + $worldData->saturated + $transientEntities->saturated,
            $terrain->failed + $entities->failed + $worldData->failed + $transientEntities->failed,
        );
    }

    public function loadChunk(
        ChunkPosition $position,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): ?LoadedChunkData {
        $payload = self::encodeDimensionPayload(
            $dimension,
            json_encode(['x' => $position->x, 'z' => $position->z], JSON_THROW_ON_ERROR),
        );
        $result = $this->request(WorldStorageOperation::LOAD_CHUNK, $payload);
        if (($result->metadata['missing'] ?? null) === true) {
            if ($result->payload !== '') {
                throw $this->failOwner('Missing chunk result unexpectedly contained data.');
            }

            $this->rememberPreloadedEntityChunk($position, $dimension, 'missing');

            return null;
        }
        if (($result->metadata['missing'] ?? null) !== false || !is_bool($result->metadata['upgraded'] ?? null)) {
            throw $this->failOwner('Loaded chunk result metadata is invalid.');
        }
        try {
            $chunk = $this->decodeLoadedChunkPayload(
                $position,
                $dimension,
                $result->payload,
                $result->metadata['entity_state'] ?? null,
            );
        } catch (Throwable $error) {
            throw $this->failOwner('Loaded chunk result is invalid.', $error);
        }
        return new LoadedChunkData($chunk, $result->metadata['upgraded']);
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $submission = $this->enqueueWorldDataSave($worldData);
        if ($submission->status === PersistenceSubmission::SATURATED
            || $submission->status === PersistenceSubmission::STALE) {
            throw $this->failOwner('World metadata persistence queue rejected the current state.');
        }
        foreach ($this->drainWorldDataSaves($this->requestTimeoutMilliseconds) as $completion) {
            if (!$completion->successful) {
                throw $this->failOwner('World metadata persistence failed: ' . $completion->failureCode);
            }
        }
    }

    public function enqueueWorldDataSave(WorldData $worldData): PersistenceEnqueueResult
    {
        $this->assertOpen();
        if ($this->worldDataRevision >= PHP_INT_MAX) {
            throw $this->failOwner('World metadata persistence revision is exhausted.');
        }
        $result = $this->worldDataWriteQueue->enqueue(
            'world:data',
            $this->worldDataRevision + 1,
            $this->worldDataCodec->encode($worldData),
        );
        if ($result->status !== PersistenceSubmission::SATURATED
            && $result->status !== PersistenceSubmission::STALE) {
            ++$this->worldDataRevision;
            $this->data = $worldData;
        }

        return $result;
    }

    public function pollWorldDataSaves(int $maximumCompletions = 16): array
    {
        $this->assertOpen();
        if ($maximumCompletions < 1 || $maximumCompletions > 256) {
            throw new \InvalidArgumentException('World metadata persistence completion limit is invalid.');
        }
        $this->advancePersistence();
        $completions = array_splice($this->deferredWorldDataCompletions, 0, $maximumCompletions);
        while (count($completions) < $maximumCompletions) {
            $completion = $this->worldDataWriteQueue->takeCompletion();
            if (!$completion instanceof PersistenceWriteCompletion) {
                break;
            }
            $completions[] = $completion;
        }

        return $completions;
    }

    public function saveChunk(
        ChunkSaveData $chunkData,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): void {
        $result = $this->request(
            WorldStorageOperation::SAVE_CHUNK,
            self::encodeDimensionPayload(
                $dimension,
                $this->chunks->encode($chunkData->chunk, $this->blockStates),
            ),
        );
        if (($result->metadata['revision'] ?? null) !== $chunkData->revision || $result->payload !== '') {
            throw $this->failOwner('Chunk save acknowledgement has the wrong revision.');
        }
    }

    public function loadEntityChunk(
        ChunkPosition $position,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): ?EntityChunkSnapshot {
        $preloadKey = self::dimensionChunkKey($dimension, $position);
        $preloaded = $this->preloadedEntityChunks[$preloadKey] ?? null;
        if ($preloaded !== null) {
            unset($this->preloadedEntityChunks[$preloadKey]);
            if ($preloaded['state'] === 'corrupt') {
                throw new CorruptEntityPersistenceException($position);
            }

            return $preloaded['snapshot'];
        }
        $payload = self::encodeDimensionPayload(
            $dimension,
            json_encode(['x' => $position->x, 'z' => $position->z], JSON_THROW_ON_ERROR),
        );
        $result = $this->request(WorldStorageOperation::LOAD_ENTITY_CHUNK, $payload, false);
        $missing = $result->metadata['missing'] ?? null;
        if ($missing === true) {
            if ($result->payload !== '') {
                throw $this->failOwner('Missing entity snapshot unexpectedly contained data.');
            }

            return null;
        }
        if ($missing !== false) {
            throw $this->failOwner('Entity snapshot result metadata is invalid.');
        }
        try {
            $snapshot = $this->entityPersistenceCodec->decode($result->payload);
        } catch (Throwable $error) {
            throw $this->failOwner('Entity snapshot result is invalid.', $error);
        }
        if ($snapshot->chunk->x !== $position->x || $snapshot->chunk->z !== $position->z) {
            throw $this->failOwner('Entity snapshot result has the wrong chunk owner.');
        }

        return $snapshot;
    }

    public function saveEntityChunk(
        EntityChunkSnapshot $snapshot,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): void {
        $result = $this->request(
            WorldStorageOperation::SAVE_ENTITY_CHUNK,
            self::encodeDimensionPayload($dimension, $this->entityPersistenceCodec->encode($snapshot)),
            false,
        );
        if (($result->metadata['revision'] ?? null) !== $snapshot->chunkRevision || $result->payload !== '') {
            throw $this->failOwner('Entity snapshot save acknowledgement has the wrong revision.');
        }
        unset($this->preloadedEntityChunks[self::dimensionChunkKey($dimension, $snapshot->chunk)]);
    }

    public function loadTransientEntities(string $namespace): ?string
    {
        self::validateTransientNamespace($namespace);
        if (array_key_exists($namespace, $this->transientEntityCache)) {
            return $this->transientEntityCache[$namespace];
        }
        $result = $this->request(WorldStorageOperation::LOAD_TRANSIENT_ENTITIES, $namespace, false);
        $missing = $result->metadata['missing'] ?? null;
        if ($missing === true) {
            if ($result->payload !== '') {
                throw $this->failOwner('Missing transient entity state unexpectedly contained data.');
            }

            return $this->transientEntityCache[$namespace] = null;
        }
        if ($missing !== false || strlen($result->payload) > self::MAXIMUM_TRANSIENT_ENTITY_BYTES) {
            throw $this->failOwner('Transient entity state result is invalid.');
        }

        return $this->transientEntityCache[$namespace] = $result->payload;
    }

    public function saveTransientEntities(string $namespace, ?string $payload): void
    {
        self::validateTransientNamespace($namespace);
        if ($payload !== null && strlen($payload) > self::MAXIMUM_TRANSIENT_ENTITY_BYTES) {
            throw new \InvalidArgumentException('Transient entity state exceeds its size limit.');
        }
        $revision = ($this->transientEntityRevisions[$namespace] ?? 0) + 1;
        $result = $this->transientEntityWriteQueue->enqueue(
            $namespace,
            $revision,
            pack('C', strlen($namespace)) . $namespace . ($payload === null ? "\x00" : "\x01" . $payload),
        );
        if ($result->status === PersistenceSubmission::SATURATED
            || $result->status === PersistenceSubmission::STALE) {
            throw $this->failOwner('Transient entity persistence queue rejected the current state.');
        }
        $this->transientEntityRevisions[$namespace] = $revision;
        $this->transientEntityCache[$namespace] = $payload;
    }

    private static function validateTransientNamespace(string $namespace): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('Transient entity namespace is invalid.');
        }
    }

    public function enqueueEntityChunkSave(
        EntityChunkSnapshot $snapshot,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): PersistenceEnqueueResult {
        $this->assertOpen();

        return $this->entityWriteQueue->enqueue(
            self::persistenceKey('entity', $dimension, $snapshot->chunk),
            $snapshot->chunkRevision,
            self::encodeDimensionPayload($dimension, $this->entityPersistenceCodec->encode($snapshot)),
        );
    }

    public function pollEntityChunkSaves(
        int $maximumCompletions = 256,
        ?WorldDimension $dimension = null,
    ): array {
        $this->assertOpen();
        if ($maximumCompletions < 1 || $maximumCompletions > 256) {
            throw new \InvalidArgumentException('Entity persistence completion limit is invalid.');
        }
        $this->advancePersistence();
        $available = $this->deferredEntityCompletions;
        $this->deferredEntityCompletions = [];
        while (count($available) < $maximumCompletions) {
            $completion = $this->entityWriteQueue->takeCompletion();
            if (!$completion instanceof PersistenceWriteCompletion) {
                break;
            }
            $chunk = self::entityChunkPositionFor($completion);
            $completionDimension = self::dimensionForPersistenceKey($completion->key, 'entity');
            $detail = $this->entityWriteFailureDetails[$completion->requestId] ?? null;
            unset($this->entityWriteFailureDetails[$completion->requestId]);
            $available[] = new EntityChunkSaveCompletion(
                $chunk,
                $completion->revision,
                $completion->successful,
                $completion->failureCode,
                $detail,
                $completionDimension,
            );
        }

        [$completions, $this->deferredEntityCompletions] = self::partitionCompletions(
            $available,
            $maximumCompletions,
            static fn(EntityChunkSaveCompletion $completion): bool => $dimension === null
                || $completion->dimension === $dimension,
        );

        return $completions;
    }

    public function drainEntityChunkSaves(
        int $timeoutMilliseconds,
        ?WorldDimension $dimension = null,
    ): array {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('Entity persistence drain timeout is invalid.');
        }
        $all = [];
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        do {
            $all = [...$all, ...$this->pollEntityChunkSaves(dimension: $dimension)];
            $snapshot = $this->entityWriteQueue->snapshot();
            if ($snapshot->queued === 0 && $snapshot->inFlight === 0
                && (!$this->asyncWriteIsEntity || $this->asyncWrite === null)) {
                return $all;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        throw $this->failOwner('Timed out draining queued entity persistence.');
    }

    public function transferEntityOwnership(
        EntityOwnershipTransfer $transfer,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): EntityOwnershipTransferResult {
        $result = $this->request(
            WorldStorageOperation::TRANSFER_ENTITY_OWNERSHIP,
            self::encodeDimensionPayload($dimension, $this->entityOwnershipTransfers->encode($transfer)),
            false,
        );
        $committed = $this->decodeEntityOwnershipTransferResult($result, $transfer);
        unset(
            $this->preloadedEntityChunks[self::dimensionChunkKey($dimension, $transfer->sourceAfter->chunk)],
            $this->preloadedEntityChunks[self::dimensionChunkKey($dimension, $transfer->destinationAfter->chunk)],
        );

        return $committed;
    }

    public function enqueueEntityOwnershipTransfer(
        EntityOwnershipTransfer $transfer,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): bool {
        $this->assertOpen();
        $uuid = $dimension->value . "\x00" . $transfer->uuid;
        $activeTransfer = $this->asyncEntityTransfer;
        if (($activeTransfer !== null && $this->asyncEntityTransferDimension === $dimension
                && $activeTransfer->uuid === $transfer->uuid)
            || isset($this->queuedEntityTransfers[$uuid])) {
            return false;
        }
        if (count($this->queuedEntityTransfers) >= self::MAXIMUM_QUEUED_ENTITY_TRANSFERS) {
            return false;
        }
        $this->queuedEntityTransfers[$uuid] = ['transfer' => $transfer, 'dimension' => $dimension];

        return true;
    }

    /** @return list<EntityOwnershipTransferCompletion> */
    public function pollEntityOwnershipTransfers(
        int $maximumCompletions = 256,
        ?WorldDimension $dimension = null,
    ): array {
        $this->assertOpen();
        if ($maximumCompletions < 1 || $maximumCompletions > 256) {
            throw new \InvalidArgumentException('Entity ownership transfer completion limit is invalid.');
        }
        $this->advancePersistence();

        [$completions, $this->entityTransferCompletions] = self::partitionCompletions(
            $this->entityTransferCompletions,
            $maximumCompletions,
            static fn(EntityOwnershipTransferCompletion $completion): bool => $dimension === null
                || $completion->dimension === $dimension,
        );

        return $completions;
    }

    /** @return list<EntityOwnershipTransferCompletion> */
    public function drainEntityOwnershipTransfers(
        int $timeoutMilliseconds,
        ?WorldDimension $dimension = null,
    ): array {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('Entity ownership transfer drain timeout is invalid.');
        }
        $all = [];
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        do {
            $all = [...$all, ...$this->pollEntityOwnershipTransfers(dimension: $dimension)];
            if ($this->queuedEntityTransfers === [] && $this->asyncEntityTransfer === null) {
                return $all;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        throw $this->failOwner('Timed out draining entity ownership transfers.');
    }

    /** Queues an immutable canonical snapshot without waiting for disk I/O. */
    public function enqueueChunkSave(
        ChunkSaveData $chunkData,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): PersistenceEnqueueResult {
        $this->assertOpen();

        return $this->writeQueue->enqueue(
            self::persistenceKey('chunk', $dimension, $chunkData->chunk->position),
            $chunkData->revision,
            self::encodeDimensionPayload(
                $dimension,
                $this->chunks->encode($chunkData->chunk, $this->blockStates),
            ),
        );
    }

    public static function chunkPositionFor(PersistenceWriteCompletion $completion): ChunkPosition
    {
        return self::positionForPersistenceKey($completion->key, 'chunk');
    }

    public static function entityChunkPositionFor(PersistenceWriteCompletion $completion): ChunkPosition
    {
        return self::entityChunkPositionForKey($completion->key);
    }

    private static function entityChunkPositionForKey(string $key): ChunkPosition
    {
        return self::positionForPersistenceKey($key, 'entity');
    }

    /** Deduplicates a coordinate and returns false when it is already queued, in flight, or awaiting collection. */
    public function requestChunkLoad(
        ChunkPosition $position,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): bool {
        $this->assertOpen();
        $key = self::dimensionChunkKey($dimension, $position);
        if (isset($this->loadKeys[$key])) {
            return false;
        }
        if (count($this->loadKeys) >= 1_024) {
            throw new \OverflowException('Chunk load request queue is full.');
        }
        $this->loadKeys[$key] = true;
        $this->queuedLoads[$key] = ['position' => $position, 'dimension' => $dimension];

        return true;
    }

    /**
     * @phpstan-impure Polling advances external storage work and consumes completed loads.
     * @return list<ChunkLoadCompletion>
     */
    public function pollChunkLoads(
        int $maximumCompletions = 256,
        ?WorldDimension $dimension = null,
    ): array {
        $this->assertOpen();
        if ($maximumCompletions < 1 || $maximumCompletions > 256) {
            throw new \InvalidArgumentException('Chunk load completion limit is invalid.');
        }
        $this->advancePersistence();
        [$completions, $this->loadCompletions] = self::partitionCompletions(
            $this->loadCompletions,
            $maximumCompletions,
            static fn(ChunkLoadCompletion $completion): bool => $dimension === null
                || $completion->dimension === $dimension,
        );
        foreach ($completions as $completion) {
            unset($this->loadKeys[self::dimensionChunkKey($completion->dimension, $completion->position)]);
        }

        return $completions;
    }

    /**
     * @phpstan-impure Polling advances external storage work and consumes completed writes.
     * @return list<PersistenceWriteCompletion>
     */
    public function pollChunkSaves(
        int $maximumCompletions = 256,
        ?WorldDimension $dimension = null,
    ): array {
        $this->assertOpen();
        if ($maximumCompletions < 1 || $maximumCompletions > 256) {
            throw new \InvalidArgumentException('Chunk persistence completion limit is invalid.');
        }
        $this->advancePersistence();
        $available = $this->deferredCompletions;
        $this->deferredCompletions = [];
        while (count($available) < $maximumCompletions) {
            $completion = $this->writeQueue->takeCompletion();
            if (!$completion instanceof PersistenceWriteCompletion) {
                break;
            }
            $available[] = $completion;
        }

        [$completions, $this->deferredCompletions] = self::partitionCompletions(
            $available,
            $maximumCompletions,
            static fn(PersistenceWriteCompletion $completion): bool => $dimension === null
                || self::dimensionForPersistenceKey($completion->key, 'chunk') === $dimension,
        );

        return $completions;
    }

    /**
     * @phpstan-impure
     * @return list<PersistenceWriteCompletion>
     */
    public function drainChunkSaves(
        int $timeoutMilliseconds,
        ?WorldDimension $dimension = null,
    ): array {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('Chunk persistence drain timeout is invalid.');
        }
        $all = [];
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        do {
            $all = [...$all, ...$this->pollChunkSaves(dimension: $dimension)];
            $this->retainWorldDataCompletions();
            $snapshot = $this->writeQueue->snapshot();
            $entitySnapshot = $this->entityWriteQueue->snapshot();
            $worldDataSnapshot = $this->worldDataWriteQueue->snapshot();
            $transientEntitySnapshot = $this->transientEntityWriteQueue->snapshot();
            $this->retainTransientEntityCompletions();
            if ($snapshot->queued === 0 && $snapshot->inFlight === 0
                && $entitySnapshot->queued === 0 && $entitySnapshot->inFlight === 0
                && $worldDataSnapshot->queued === 0 && $worldDataSnapshot->inFlight === 0
                && $transientEntitySnapshot->queued === 0 && $transientEntitySnapshot->inFlight === 0
                && $this->asyncWrite === null && $this->asyncLoad === null
                && $this->asyncEntityTransfer === null && $this->queuedEntityTransfers === []
                && $this->asyncOutgoing === '' && $this->queuedLoads === []) {
                return $all;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        throw $this->failOwner('Timed out draining queued chunk persistence.');
    }

    /** @return list<PersistenceWriteCompletion> */
    private function drainWorldDataSaves(int $timeoutMilliseconds): array
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('World metadata persistence drain timeout is invalid.');
        }
        $all = [];
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        do {
            $all = [...$all, ...$this->pollWorldDataSaves()];
            $snapshot = $this->worldDataWriteQueue->snapshot();
            if ($snapshot->queued === 0 && $snapshot->inFlight === 0
                && (!$this->asyncWriteIsWorldData || $this->asyncWrite === null)) {
                return $all;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        throw $this->failOwner('Timed out draining queued world metadata persistence.');
    }

    private function retainWorldDataCompletions(): void
    {
        while (($completion = $this->worldDataWriteQueue->takeCompletion()) instanceof PersistenceWriteCompletion) {
            $this->deferredWorldDataCompletions[] = $completion;
        }
    }

    private function retainTransientEntityCompletions(): void
    {
        while (($completion = $this->transientEntityWriteQueue->takeCompletion()) instanceof PersistenceWriteCompletion) {
            if (!$completion->successful) {
                throw $this->failOwner('Transient entity persistence failed: ' . $completion->failureCode);
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if (!$this->startupReady) {
            $this->closed = true;
            $this->closeProcess();

            return;
        }
        $failure = null;
        try {
            $this->drainChunkSaves($this->requestTimeoutMilliseconds);
        } catch (Throwable $error) {
            $failure = $error;
        }
        $this->closed = true;
        try {
            if (is_resource($this->connection)) {
                stream_set_blocking($this->connection, true);
                $this->writeFrame(new WorkerFrame(WorkerFrameKind::SHUTDOWN, $this->epoch));
                $result = $this->readFrame();
                if ($result === null || $result->kind !== WorkerFrameKind::RESULT
                    || !hash_equals($this->epoch, $result->epoch) || $result->taskId !== 0) {
                    throw new WorldStorageException('World storage owner did not confirm provider closure.');
                }
                $this->ownerConfirmedClosed = true;
            }
        } catch (Throwable $error) {
            $failure ??= $error;
        }
        $this->closeProcess();
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function ownerConfirmedClosed(): bool
    {
        return $this->ownerConfirmedClosed;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try {
                $this->close();
            } catch (Throwable) {
            }
        }
    }

    private function beginLaunch(): void
    {
        if (!is_file($this->entryPoint)) {
            throw new WorldStorageException('World storage process entry point is unavailable.');
        }
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!is_resource($listener)) {
            throw new WorldStorageException('World storage IPC listener could not be created.');
        }
        $endpoint = stream_socket_get_name($listener, false);
        if (!is_string($endpoint) || $endpoint === '') {
            @fclose($listener);
            throw new WorldStorageException('World storage IPC endpoint is unavailable.');
        }
        $token = random_bytes(32);
        if ($this->processLauncher !== null) {
            if ($this->processLauncherTaskType < 1) {
                @fclose($listener);
                throw new WorldStorageException('World storage process launcher task is invalid.');
            }
            $submission = $this->processLauncher->submit(
                $this->processLauncherTaskType,
                (new SpawnWorldStorageOwnerRequest(
                    bin2hex($this->epoch),
                    $this->applicationVersion,
                    'tcp://' . $endpoint,
                    bin2hex($token),
                ))->encode(),
                function (\Bedriox\Server\Worker\WorkerResult $result): void {
                    if ($result->status !== WorkerResultStatus::SUCCESS) {
                        $this->externalLaunchFailure = $result->failureCode ?? $result->status->value;
                    }
                },
                hrtime(true) + 10_000_000_000,
            );
            if (!$submission->isAccepted()) {
                @fclose($listener);
                throw new WorldStorageException('World storage process launch queue rejected the request.');
            }
            $this->externallyLaunched = true;
            $this->startupListener = $listener;
            stream_set_blocking($this->startupListener, false);
            $this->startupToken = $token;
            $this->startupNonce = bin2hex(random_bytes(16));
            $this->startupDeadlineNanoseconds = hrtime(true) + max(5_000, $this->requestTimeoutMilliseconds) * 1_000_000;

            return;
        }
        $pipes = [];
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $this->entryPoint,
                'world',
                bin2hex($this->epoch),
                $this->applicationVersion,
                'tcp://' . $endpoint,
                bin2hex($token),
            ),
            [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
                2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
            ],
            $pipes,
            dirname($this->entryPoint, 2),
            ProcessEnvironment::allowlisted(),
            ['bypass_shell' => true, 'blocking_pipes' => false],
        );
        if (!is_resource($process)) {
            @fclose($listener);
            throw new WorldStorageException('World storage process could not be started.');
        }
        $this->process = $process;
        $this->startupListener = $listener;
        stream_set_blocking($this->startupListener, false);
        $this->startupToken = $token;
        $this->startupNonce = bin2hex(random_bytes(16));
        // Startup has always had its own five-second process-handshake allowance;
        // short request deadlines apply only after the provider is ready.
        $this->startupDeadlineNanoseconds = hrtime(true) + max(5_000, $this->requestTimeoutMilliseconds) * 1_000_000;
    }

    private function assertStartupProcessRunning(): void
    {
        if ($this->externallyLaunched) {
            if ($this->externalLaunchFailure !== null) {
                throw $this->failOwner('World storage process launch failed: ' . $this->externalLaunchFailure);
            }

            return;
        }
        if (!is_resource($this->process)) {
            throw $this->failOwner('World storage process exited during startup.');
        }
        // proc_get_status() may synchronously wait on Windows process bookkeeping.
        // Socket EOF and the bounded startup deadline detect an exited owner without
        // placing that platform cost on the authoritative loop.
    }

    private function request(
        WorldStorageOperation $operation,
        string $payload,
        bool $drainQueuedPersistence = true,
    ): WorkerFrame {
        $this->assertOpen();
        $pending = $this->writeQueue->snapshot();
        $pendingEntities = $this->entityWriteQueue->snapshot();
        $pendingWorldData = $this->worldDataWriteQueue->snapshot();
        $pendingTransientEntities = $this->transientEntityWriteQueue->snapshot();
        if ($pending->queued > 0 || $pending->inFlight > 0 || $this->asyncWrite !== null
            || $pendingEntities->queued > 0 || $pendingEntities->inFlight > 0
            || $pendingWorldData->queued > 0 || $pendingWorldData->inFlight > 0
            || $pendingTransientEntities->queued > 0 || $pendingTransientEntities->inFlight > 0
            || $this->asyncLoad !== null || $this->asyncEntityTransfer !== null
            || $this->asyncOutgoing !== '' || $this->queuedLoads !== []
            || $this->queuedEntityTransfers !== []) {
            if ($drainQueuedPersistence) {
                $drained = $this->drainChunkSaves($this->requestTimeoutMilliseconds);
                $this->deferredCompletions = [
                    ...$this->deferredCompletions,
                    ...$drained,
                ];
            } else {
                $this->settleActivePersistence($this->requestTimeoutMilliseconds);
            }
        }
        if (!is_resource($this->connection)) {
            throw new WorldProviderClosedException('World provider is closed.');
        }
        stream_set_blocking($this->connection, true);
        $taskId = $this->allocateTaskId();
        try {
            $this->writeFrame(new WorkerFrame(
                WorkerFrameKind::SUBMIT,
                $this->epoch,
                $taskId,
                $operation->value,
                WorldStorageProcessProgram::SCHEMA_VERSION,
                payload: $payload,
            ));
            $result = $this->readFrame();
        } finally {
            if (is_resource($this->connection)) {
                stream_set_blocking($this->connection, false);
            }
        }
        if ($result === null || !hash_equals($this->epoch, $result->epoch)
            || $result->taskId !== $taskId || $result->taskTypeId !== $operation->value
            || $result->schemaVersion !== WorldStorageProcessProgram::SCHEMA_VERSION) {
            throw $this->failOwner('World storage response was missing or did not match its request.');
        }
        if ($result->kind === WorkerFrameKind::FAILURE) {
            $code = is_string($result->metadata['code'] ?? null) ? $result->metadata['code'] : 'storage_failure';
            throw self::mappedFailure($code, $result->metadata);
        }
        if ($result->kind !== WorkerFrameKind::RESULT) {
            throw $this->failOwner('World storage response kind is invalid.');
        }

        return $result;
    }

    /**
     * Completes only the request which already owns the storage connection.
     *
     * Entity persistence uses this as a bounded ordering barrier. It must not
     * drain terrain work which was merely queued by active chunk streaming.
     */
    private function settleActivePersistence(int $timeoutMilliseconds): void
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('World persistence barrier timeout is invalid.');
        }
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        while ($this->asyncWrite !== null || $this->asyncLoad !== null
            || $this->asyncEntityTransfer !== null || $this->asyncOutgoing !== '') {
            $this->advancePersistence();
            if ($this->asyncWrite === null && $this->asyncLoad === null
                && $this->asyncEntityTransfer === null && $this->asyncOutgoing === '') {
                return;
            }
            if (hrtime(true) >= $deadline) {
                throw $this->failOwner('Timed out waiting for the active world persistence request.');
            }
            usleep(1_000);
        }
    }

    private function advancePersistence(): void
    {
        if (!is_resource($this->connection)) {
            throw new WorldProviderClosedException('World provider is closed.');
        }
        if ($this->asyncWrite === null && $this->asyncLoad === null && $this->asyncEntityTransfer === null) {
            $request = $this->worldDataWriteQueue->dispatch();
            if ($request instanceof PersistenceWriteRequest) {
                $this->asyncWrite = $request;
                $this->asyncWriteIsWorldData = true;
                $this->asyncWriteIsEntity = false;
                $this->asyncWriteIsTransientEntity = false;
                $this->asyncTaskId = $this->allocateTaskId();
                $this->asyncOutgoing = $this->frames->encode(new WorkerFrame(
                    WorkerFrameKind::SUBMIT,
                    $this->epoch,
                    $this->asyncTaskId,
                    WorldStorageOperation::SAVE_WORLD_DATA->value,
                    WorldStorageProcessProgram::SCHEMA_VERSION,
                    payload: $request->payload,
                ));
            } else {
                $request = $this->transientEntityWriteQueue->dispatch();
                if ($request instanceof PersistenceWriteRequest) {
                    $this->asyncWrite = $request;
                    $this->asyncWriteIsWorldData = false;
                    $this->asyncWriteIsEntity = false;
                    $this->asyncWriteIsTransientEntity = true;
                    $this->asyncTaskId = $this->allocateTaskId();
                    $this->asyncOutgoing = $this->frames->encode(new WorkerFrame(
                        WorkerFrameKind::SUBMIT,
                        $this->epoch,
                        $this->asyncTaskId,
                        WorldStorageOperation::SAVE_TRANSIENT_ENTITIES->value,
                        WorldStorageProcessProgram::SCHEMA_VERSION,
                        payload: $request->payload,
                    ));
                }
            }
            if ($this->asyncWrite === null) {
                $transferUuid = array_key_first($this->queuedEntityTransfers);
                if (is_string($transferUuid)) {
                    $queuedTransfer = $this->queuedEntityTransfers[$transferUuid];
                    unset($this->queuedEntityTransfers[$transferUuid]);
                    $transfer = $queuedTransfer['transfer'];
                    $this->asyncEntityTransfer = $transfer;
                    $this->asyncEntityTransferDimension = $queuedTransfer['dimension'];
                    $this->asyncTaskId = $this->allocateTaskId();
                    $this->asyncOutgoing = $this->frames->encode(new WorkerFrame(
                        WorkerFrameKind::SUBMIT,
                        $this->epoch,
                        $this->asyncTaskId,
                        WorldStorageOperation::TRANSFER_ENTITY_OWNERSHIP->value,
                        WorldStorageProcessProgram::SCHEMA_VERSION,
                        payload: self::encodeDimensionPayload(
                            $this->asyncEntityTransferDimension,
                            $this->entityOwnershipTransfers->encode($transfer),
                        ),
                    ));
                } else {
                    $loadKey = array_key_first($this->queuedLoads);
                }
                if ($this->asyncEntityTransfer === null && is_string($loadKey ?? null)) {
                    $queuedLoad = $this->queuedLoads[$loadKey];
                    unset($this->queuedLoads[$loadKey]);
                    $position = $queuedLoad['position'];
                    $this->asyncLoad = $position;
                    $this->asyncLoadDimension = $queuedLoad['dimension'];
                    $this->asyncTaskId = $this->allocateTaskId();
                    $this->asyncOutgoing = $this->frames->encode(new WorkerFrame(
                        WorkerFrameKind::SUBMIT,
                        $this->epoch,
                        $this->asyncTaskId,
                        WorldStorageOperation::LOAD_CHUNK->value,
                        WorldStorageProcessProgram::SCHEMA_VERSION,
                        payload: self::encodeDimensionPayload(
                            $this->asyncLoadDimension,
                            json_encode(['x' => $position->x, 'z' => $position->z], JSON_THROW_ON_ERROR),
                        ),
                    ));
                } elseif ($this->asyncEntityTransfer === null) {
                    $request = $this->entityWriteQueue->dispatch();
                    $this->asyncWriteIsEntity = $request instanceof PersistenceWriteRequest;
                    $this->asyncWriteIsWorldData = false;
                    $this->asyncWriteIsTransientEntity = false;
                    if (!$request instanceof PersistenceWriteRequest) {
                        $request = $this->writeQueue->dispatch();
                        $this->asyncWriteIsEntity = false;
                    }
                    if ($request instanceof PersistenceWriteRequest) {
                        $this->asyncWrite = $request;
                        $this->asyncTaskId = $this->allocateTaskId();
                        $operation = $this->asyncWriteIsEntity
                            ? WorldStorageOperation::SAVE_ENTITY_CHUNK
                            : WorldStorageOperation::SAVE_CHUNK;
                        $this->asyncOutgoing = $this->frames->encode(new WorkerFrame(
                            WorkerFrameKind::SUBMIT,
                            $this->epoch,
                            $this->asyncTaskId,
                            $operation->value,
                            WorldStorageProcessProgram::SCHEMA_VERSION,
                            payload: $request->payload,
                        ));
                    }
                }
            }
        }
        if ($this->asyncOutgoing !== '') {
            $written = @fwrite($this->connection, $this->asyncOutgoing);
            if ($written === false) {
                throw $this->failOwner('World storage asynchronous IPC write failed.');
            }
            if ($written > 0) {
                $this->asyncOutgoing = substr($this->asyncOutgoing, $written);
            }
        }
        $bytes = @fread($this->connection, 262_144);
        if (!is_string($bytes) || $bytes === '') {
            return;
        }
        try {
            $frames = $this->asyncDecoder->push($bytes, 16);
        } catch (Throwable $error) {
            throw $this->failOwner('World storage asynchronous response framing failed.', $error);
        }
        foreach ($frames as $frame) {
            if ($this->asyncEntityTransfer instanceof EntityOwnershipTransfer) {
                $transfer = $this->asyncEntityTransfer;
                if (!hash_equals($this->epoch, $frame->epoch)
                    || $frame->taskId !== $this->asyncTaskId
                    || $frame->taskTypeId !== WorldStorageOperation::TRANSFER_ENTITY_OWNERSHIP->value
                    || $frame->schemaVersion !== WorldStorageProcessProgram::SCHEMA_VERSION) {
                    throw $this->failOwner('World storage entity-transfer response did not match its request.');
                }
                $committed = $frame->kind === WorkerFrameKind::RESULT
                    ? $this->decodeEntityOwnershipTransferResult($frame, $transfer)
                    : null;
                $successful = $committed instanceof EntityOwnershipTransferResult;
                $failureCode = $successful
                    ? null
                    : (is_string($frame->metadata['code'] ?? null) ? $frame->metadata['code'] : 'storage_failure');
                $this->entityTransferCompletions[] = new EntityOwnershipTransferCompletion(
                    $transfer,
                    $successful,
                    $failureCode,
                    $successful || !is_string($frame->metadata['detail'] ?? null)
                        ? null
                        : $frame->metadata['detail'],
                    $committed,
                    $this->asyncEntityTransferDimension,
                );
                if ($successful) {
                    unset(
                        $this->preloadedEntityChunks[self::dimensionChunkKey(
                            $this->asyncEntityTransferDimension,
                            $transfer->sourceAfter->chunk,
                        )],
                        $this->preloadedEntityChunks[self::dimensionChunkKey(
                            $this->asyncEntityTransferDimension,
                            $transfer->destinationAfter->chunk,
                        )],
                    );
                }
                $this->asyncEntityTransfer = null;
                $this->asyncTaskId = 0;
                if (count($frames) > 1) {
                    throw $this->failOwner('World storage returned more than one asynchronous completion.');
                }

                continue;
            }
            if ($this->asyncLoad instanceof ChunkPosition) {
                $this->acceptAsyncLoad($frame, $this->asyncLoad, $this->asyncLoadDimension);
                $this->asyncLoad = null;
                $this->asyncTaskId = 0;
                if (count($frames) > 1) {
                    throw $this->failOwner('World storage returned more than one asynchronous completion.');
                }

                continue;
            }
            $request = $this->asyncWrite;
            $operation = $this->asyncWriteIsWorldData
                ? WorldStorageOperation::SAVE_WORLD_DATA
                : ($this->asyncWriteIsTransientEntity
                    ? WorldStorageOperation::SAVE_TRANSIENT_ENTITIES
                    : ($this->asyncWriteIsEntity
                    ? WorldStorageOperation::SAVE_ENTITY_CHUNK
                    : WorldStorageOperation::SAVE_CHUNK));
            if (!$request instanceof PersistenceWriteRequest
                || !hash_equals($this->epoch, $frame->epoch)
                || $frame->taskId !== $this->asyncTaskId
                || $frame->taskTypeId !== $operation->value
                || $frame->schemaVersion !== WorldStorageProcessProgram::SCHEMA_VERSION) {
                throw $this->failOwner('World storage asynchronous response did not match its request.');
            }
            $successful = $frame->kind === WorkerFrameKind::RESULT
                && ($this->asyncWriteIsWorldData || $this->asyncWriteIsTransientEntity
                    || ($frame->metadata['revision'] ?? null) === $request->revision)
                && $frame->payload === '';
            $failureCode = $successful
                ? null
                : (is_string($frame->metadata['code'] ?? null) ? $frame->metadata['code'] : 'storage_failure');
            $queue = $this->asyncWriteIsWorldData
                ? $this->worldDataWriteQueue
                : ($this->asyncWriteIsTransientEntity
                    ? $this->transientEntityWriteQueue
                    : ($this->asyncWriteIsEntity ? $this->entityWriteQueue : $this->writeQueue));
            if ($this->asyncWriteIsEntity && !$successful && is_string($frame->metadata['detail'] ?? null)) {
                $this->entityWriteFailureDetails[$request->id] = $frame->metadata['detail'];
            }
            $queue->complete(
                $request->id,
                $request->key,
                $request->revision,
                $successful,
                $failureCode,
            );
            if ($this->asyncWriteIsEntity && $successful) {
                unset($this->preloadedEntityChunks[self::dimensionChunkKey(
                    self::dimensionForPersistenceKey($request->key, 'entity'),
                    self::entityChunkPositionForKey($request->key),
                )]);
            }
            $this->asyncWrite = null;
            $this->asyncWriteIsEntity = false;
            $this->asyncWriteIsWorldData = false;
            $this->asyncWriteIsTransientEntity = false;
            $this->asyncTaskId = 0;
            if (count($frames) > 1) {
                throw $this->failOwner('World storage returned more than one asynchronous completion.');
            }
        }
    }

    private function decodeEntityOwnershipTransferResult(
        WorkerFrame $frame,
        EntityOwnershipTransfer $transfer,
    ): EntityOwnershipTransferResult {
        try {
            $result = $this->entityOwnershipTransferResults->decode($frame->payload);
        } catch (Throwable $error) {
            throw $this->failOwner('World storage entity-transfer result is invalid.', $error);
        }
        $source = $result->sourceAfter;
        $destination = $result->destinationAfter;
        $movedRevision = $destination->revisions()[$transfer->uuid] ?? null;
        if ($source->worldName !== $transfer->sourceAfter->worldName
            || $destination->worldName !== $transfer->destinationAfter->worldName
            || $source->chunk->x !== $transfer->sourceAfter->chunk->x
            || $source->chunk->z !== $transfer->sourceAfter->chunk->z
            || $destination->chunk->x !== $transfer->destinationAfter->chunk->x
            || $destination->chunk->z !== $transfer->destinationAfter->chunk->z
            || isset($source->revisions()[$transfer->uuid])
            || !is_int($movedRevision)
            || $movedRevision <= $transfer->expectedEntityRevision
            || ($frame->metadata['source_revision'] ?? null) !== $source->chunkRevision
            || ($frame->metadata['destination_revision'] ?? null) !== $destination->chunkRevision) {
            throw $this->failOwner('Entity ownership transfer acknowledgement has inconsistent committed state.');
        }

        return $result;
    }

    private function acceptAsyncLoad(
        WorkerFrame $frame,
        ChunkPosition $position,
        WorldDimension $dimension,
    ): void {
        if (!hash_equals($this->epoch, $frame->epoch) || $frame->taskId !== $this->asyncTaskId
            || $frame->taskTypeId !== WorldStorageOperation::LOAD_CHUNK->value
            || $frame->schemaVersion !== WorldStorageProcessProgram::SCHEMA_VERSION) {
            throw $this->failOwner('World storage chunk-load response did not match its request.');
        }
        if ($frame->kind === WorkerFrameKind::FAILURE) {
            $code = is_string($frame->metadata['code'] ?? null) ? $frame->metadata['code'] : 'storage_failure';
            $this->loadCompletions[] = new ChunkLoadCompletion($position, null, false, $code, $dimension);

            return;
        }
        if ($frame->kind !== WorkerFrameKind::RESULT) {
            throw $this->failOwner('World storage chunk-load response kind is invalid.');
        }
        if (($frame->metadata['missing'] ?? null) === true && $frame->payload === '') {
            $this->rememberPreloadedEntityChunk($position, $dimension, 'missing');
            $this->loadCompletions[] = new ChunkLoadCompletion($position, null, true, dimension: $dimension);

            return;
        }
        if (($frame->metadata['missing'] ?? null) !== false || !is_bool($frame->metadata['upgraded'] ?? null)) {
            throw $this->failOwner('World storage chunk-load response metadata is invalid.');
        }
        try {
            $chunk = $this->decodeLoadedChunkPayload(
                $position,
                $dimension,
                $frame->payload,
                $frame->metadata['entity_state'] ?? null,
            );
        } catch (Throwable $error) {
            throw $this->failOwner('World storage chunk-load response payload is invalid.', $error);
        }
        $this->loadCompletions[] = new ChunkLoadCompletion(
            $position,
            new LoadedChunkData($chunk, $frame->metadata['upgraded']),
            false,
            dimension: $dimension,
        );
    }

    /** @param bool|int|string|null $entityState */
    private function decodeLoadedChunkPayload(
        ChunkPosition $position,
        WorldDimension $dimension,
        string $payload,
        bool|int|string|null $entityState,
    ): \Bedriox\Server\World\Chunk {
        if (!is_string($entityState) || !in_array($entityState, ['loaded', 'missing', 'corrupt'], true)) {
            throw $this->failOwner('World storage chunk-load entity metadata is invalid.');
        }
        try {
            $parts = $this->chunkLoadPayloads->decode($payload);
            $chunk = $this->chunks->decode($parts['chunk'], $this->blockStates);
            $entitySnapshot = $parts['entities'] === null
                ? null
                : $this->entityPersistenceCodec->decode($parts['entities']);
        } catch (Throwable $error) {
            throw $this->failOwner('World storage chunk-load response payload is invalid.', $error);
        }
        if ($chunk->position->x !== $position->x || $chunk->position->z !== $position->z
            || ($entitySnapshot !== null
                && ($entitySnapshot->chunk->x !== $position->x || $entitySnapshot->chunk->z !== $position->z))
            || ($entityState === 'loaded') !== ($entitySnapshot !== null)) {
            throw $this->failOwner('World storage chunk-load response has inconsistent ownership.');
        }
        $this->rememberPreloadedEntityChunk($position, $dimension, $entityState, $entitySnapshot);

        return $chunk;
    }

    /** @param 'loaded'|'missing'|'corrupt' $state */
    private function rememberPreloadedEntityChunk(
        ChunkPosition $position,
        WorldDimension $dimension,
        string $state,
        ?EntityChunkSnapshot $snapshot = null,
    ): void {
        $key = self::dimensionChunkKey($dimension, $position);
        unset($this->preloadedEntityChunks[$key]);
        $this->preloadedEntityChunks[$key] = ['state' => $state, 'snapshot' => $snapshot];
        while (count($this->preloadedEntityChunks) > self::MAXIMUM_PRELOADED_ENTITY_CHUNKS) {
            $oldest = array_key_first($this->preloadedEntityChunks);
            unset($this->preloadedEntityChunks[$oldest]);
        }
    }

    private static function encodeDimensionPayload(WorldDimension $dimension, string $payload): string
    {
        return chr(match ($dimension) {
            WorldDimension::OVERWORLD => 0,
            WorldDimension::NETHER => 1,
            WorldDimension::END => 2,
        }) . $payload;
    }

    private static function dimensionChunkKey(WorldDimension $dimension, ChunkPosition $position): string
    {
        return $dimension->value . ':' . $position->key();
    }

    private static function persistenceKey(
        string $kind,
        WorldDimension $dimension,
        ChunkPosition $position,
    ): string {
        return $dimension === WorldDimension::OVERWORLD
            ? $kind . ':' . $position->key()
            : $kind . ':' . $dimension->name . ':' . $position->key();
    }

    private static function positionForPersistenceKey(string $key, string $kind): ChunkPosition
    {
        $pattern = '/^' . preg_quote($kind, '/')
            . ':(?:(OVERWORLD|NETHER|END):)?(-?(?:0|[1-9][0-9]*)):(-?(?:0|[1-9][0-9]*))$/D';
        if (preg_match($pattern, $key, $matches) !== 1) {
            throw new \InvalidArgumentException('Persistence completion does not contain a chunk coordinate key.');
        }
        $dimension = match ($matches[1] ?? '') {
            '', 'OVERWORLD' => WorldDimension::OVERWORLD,
            'NETHER' => WorldDimension::NETHER,
            'END' => WorldDimension::END,
            default => throw new \InvalidArgumentException('Persistence completion dimension is invalid.'),
        };
        $position = new ChunkPosition((int) $matches[2], (int) $matches[3]);
        if (self::persistenceKey($kind, $dimension, $position) !== $key) {
            throw new \InvalidArgumentException('Persistence completion chunk coordinate is not canonical.');
        }

        return $position;
    }

    private static function dimensionForPersistenceKey(string $key, string $kind): WorldDimension
    {
        $prefix = $kind . ':';
        if (!str_starts_with($key, $prefix)) {
            throw new \InvalidArgumentException('Persistence completion has the wrong key kind.');
        }
        foreach ([WorldDimension::NETHER, WorldDimension::END] as $dimension) {
            if (str_starts_with($key, $prefix . $dimension->name . ':')) {
                return $dimension;
            }
        }

        return WorldDimension::OVERWORLD;
    }

    /**
     * @template T
     * @param list<T> $available
     * @param callable(T): bool $accept
     * @return array{list<T>, list<T>}
     */
    private static function partitionCompletions(array $available, int $maximum, callable $accept): array
    {
        $accepted = [];
        $retained = [];
        foreach ($available as $completion) {
            if (count($accepted) < $maximum && $accept($completion)) {
                $accepted[] = $completion;
            } else {
                $retained[] = $completion;
            }
        }

        return [$accepted, $retained];
    }

    private function assertOpen(): void
    {
        $processUnavailable = !$this->externallyLaunched && !is_resource($this->process);
        if (!$this->startupReady || $this->closed || $processUnavailable || !is_resource($this->connection)) {
            throw new WorldProviderClosedException('World provider is closed.');
        }
    }

    private function allocateTaskId(): int
    {
        $id = $this->nextTaskId++;
        if ($this->nextTaskId > 0xffffffff) {
            $this->nextTaskId = 1;
        }

        return $id;
    }

    private function writeFrame(WorkerFrame $frame): void
    {
        if (!is_resource($this->connection)) {
            throw new WorldProviderClosedException('World provider is closed.');
        }
        $bytes = $this->frames->encode($frame);
        while ($bytes !== '') {
            $written = @fwrite($this->connection, $bytes);
            if (!is_int($written) || $written < 1) {
                throw $this->failOwner('World storage IPC write failed.');
            }
            $bytes = substr($bytes, $written);
        }
        fflush($this->connection);
    }

    private function readFrame(): ?WorkerFrame
    {
        if (!is_resource($this->connection)) {
            return null;
        }
        $header = $this->readExact($this->connection, WorkerFrameCodec::FIXED_HEADER_BYTES);
        if ($header === null) {
            return null;
        }
        $lengths = $this->frames->lengths($header);
        if ($lengths === null) {
            return null;
        }
        $body = $this->readExact($this->connection, $lengths['headerLength'] + $lengths['payloadLength']);

        return $body === null ? null : $this->frames->decode($header . $body);
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        if ($length === 0) {
            return '';
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = @fread($stream, max(1, $length - strlen($bytes)));
            if (!is_string($chunk) || $chunk === '') {
                return null;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function failOwner(string $message, ?Throwable $previous = null): WorldStorageException
    {
        $this->closed = true;
        $this->closeProcess();

        return new WorldStorageException($message, previous: $previous);
    }

    /** @param array<string, bool|int|string|null> $metadata */
    private static function mappedFailure(string $code, array $metadata = []): WorldProviderException
    {
        return match ($code) {
            'corrupt_chunk' => new CorruptChunkException('World storage owner reported a corrupt chunk.'),
            'corrupt_world_data' => new CorruptWorldDataException('World storage owner reported corrupt world data.'),
            'corrupt_entity_persistence' => new CorruptEntityPersistenceException(
                new ChunkPosition(
                    is_int($metadata['chunk_x'] ?? null) ? $metadata['chunk_x'] : 0,
                    is_int($metadata['chunk_z'] ?? null) ? $metadata['chunk_z'] : 0,
                ),
                'World storage owner reported corrupt entity persistence.',
            ),
            'entity_persistence_conflict' => new EntityPersistenceConflictException(
                is_string($metadata['detail'] ?? null) && $metadata['detail'] !== ''
                    ? 'World storage owner rejected stale entity persistence: ' . $metadata['detail']
                    : 'World storage owner reported a stale entity persistence operation.',
            ),
            'unsupported_world_format' => new UnsupportedWorldFormatException('World storage owner reported an unsupported format.'),
            'provider_closed' => new WorldProviderClosedException('World storage owner is closed.'),
            default => new WorldStorageException('World storage owner reported an I/O failure.'),
        };
    }

    private function closeProcess(): void
    {
        if (is_resource($this->startupListener)) {
            @fclose($this->startupListener);
        }
        $this->startupListener = null;
        if (is_resource($this->connection)) {
            @fclose($this->connection);
        }
        $this->connection = null;
        if (is_resource($this->process)) {
            $status = @proc_get_status($this->process);
            if ($status['running']) {
                @proc_terminate($this->process);
            }
            $deadline = hrtime(true) + 250_000_000;
            do {
                $status = @proc_get_status($this->process);
                if (!$status['running']) {
                    @proc_close($this->process);
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
        }
        $this->process = null;
    }
}
