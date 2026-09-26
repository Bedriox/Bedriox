<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World;

use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Persistence\OrderedPersistenceQueue;
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Persistence\PersistenceQueueStatusProvider;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\PersistenceWriteRequest;
use Bedriox\Server\Persistence\World\Internal\WorldStorageProcessProgram;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Internal\ProcessEnvironment;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
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

final class ProcessWorldProvider implements AsynchronousWorldProvider, EntityPersistenceStore, PersistenceQueueStatusProvider
{
    private const int MAXIMUM_PRELOADED_ENTITY_CHUNKS = 2_048;

    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $connection = null;
    private readonly string $epoch;
    private readonly WorkerFrameCodec $frames;
    private readonly ChunkTransferCodec $chunks;
    private readonly WorldDataIpcCodec $worldDataCodec;
    private readonly EntityPersistenceCodec $entityPersistenceCodec;
    private readonly EntityOwnershipTransferCodec $entityOwnershipTransfers;
    private readonly WorldChunkLoadPayloadCodec $chunkLoadPayloads;
    private readonly OrderedPersistenceQueue $writeQueue;
    private readonly WorkerFrameDecoder $asyncDecoder;
    private int $nextTaskId = 1;
    private ?PersistenceWriteRequest $asyncWrite = null;
    private int $asyncTaskId = 0;
    private string $asyncOutgoing = '';
    /** @var array<string, ChunkPosition> */
    private array $queuedLoads = [];
    /** @var array<string, true> */
    private array $loadKeys = [];
    private ?ChunkPosition $asyncLoad = null;
    /** @var array<string, array{state: 'loaded'|'missing'|'corrupt', snapshot: ?EntityChunkSnapshot}> */
    private array $preloadedEntityChunks = [];
    /** @var list<ChunkLoadCompletion> */
    private array $loadCompletions = [];
    /** @var list<PersistenceWriteCompletion> */
    private array $deferredCompletions = [];
    private bool $closed = false;
    private bool $ownerConfirmedClosed = false;
    private WorldData $data;

    private function __construct(
        private readonly string $applicationVersion,
        private readonly WorldStorageStartup $startup,
        private readonly BlockStateRegistry $blockStates,
        private readonly int $requestTimeoutMilliseconds,
        private readonly string $entryPoint,
    ) {
        if ($applicationVersion === '' || strlen($applicationVersion) > 128
            || $requestTimeoutMilliseconds < 100 || $requestTimeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('Process world provider configuration is invalid.');
        }
        $this->epoch = random_bytes(16);
        $this->frames = new WorkerFrameCodec();
        $this->chunks = new ChunkTransferCodec();
        $this->worldDataCodec = new WorldDataIpcCodec();
        $this->entityPersistenceCodec = EntityPersistenceCodec::vanilla();
        $this->entityOwnershipTransfers = new EntityOwnershipTransferCodec($this->entityPersistenceCodec);
        $this->chunkLoadPayloads = new WorldChunkLoadPayloadCodec();
        $this->writeQueue = new OrderedPersistenceQueue(1_024, 67_108_864, ChunkTransferCodec::MAXIMUM_ENCODED_BYTES, 1_024);
        $this->asyncDecoder = new WorkerFrameDecoder($this->frames, 33_619_968);
    }

    public static function start(
        string $applicationVersion,
        WorldStorageStartup $startup,
        BlockStateRegistry $blockStates,
        int $requestTimeoutMilliseconds = 30_000,
        ?string $entryPoint = null,
    ): self {
        $provider = new self(
            $applicationVersion,
            $startup,
            $blockStates,
            $requestTimeoutMilliseconds,
            $entryPoint ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'bedriox-io.php',
        );
        $provider->launch();

        return $provider;
    }

    public function worldData(): WorldData
    {
        $this->assertOpen();

        return $this->data;
    }

    public function persistenceQueueSnapshot(): PersistenceQueueSnapshot
    {
        return $this->writeQueue->snapshot();
    }

    public function loadChunk(ChunkPosition $position): ?LoadedChunkData
    {
        $payload = json_encode(['x' => $position->x, 'z' => $position->z], JSON_THROW_ON_ERROR);
        $result = $this->request(WorldStorageOperation::LOAD_CHUNK, $payload);
        if (($result->metadata['missing'] ?? null) === true) {
            if ($result->payload !== '') {
                throw $this->failOwner('Missing chunk result unexpectedly contained data.');
            }

            $this->rememberPreloadedEntityChunk($position, 'missing');

            return null;
        }
        if (($result->metadata['missing'] ?? null) !== false || !is_bool($result->metadata['upgraded'] ?? null)) {
            throw $this->failOwner('Loaded chunk result metadata is invalid.');
        }
        try {
            $chunk = $this->decodeLoadedChunkPayload($position, $result->payload, $result->metadata['entity_state'] ?? null);
        } catch (Throwable $error) {
            throw $this->failOwner('Loaded chunk result is invalid.', $error);
        }
        return new LoadedChunkData($chunk, $result->metadata['upgraded']);
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $this->request(WorldStorageOperation::SAVE_WORLD_DATA, $this->worldDataCodec->encode($worldData));
        $this->data = $worldData;
    }

    public function saveChunk(ChunkSaveData $chunkData): void
    {
        $result = $this->request(
            WorldStorageOperation::SAVE_CHUNK,
            $this->chunks->encode($chunkData->chunk, $this->blockStates),
        );
        if (($result->metadata['revision'] ?? null) !== $chunkData->revision || $result->payload !== '') {
            throw $this->failOwner('Chunk save acknowledgement has the wrong revision.');
        }
    }

    public function loadEntityChunk(ChunkPosition $position): ?EntityChunkSnapshot
    {
        $preloaded = $this->preloadedEntityChunks[$position->key()] ?? null;
        if ($preloaded !== null) {
            unset($this->preloadedEntityChunks[$position->key()]);
            if ($preloaded['state'] === 'corrupt') {
                throw new CorruptEntityPersistenceException($position);
            }

            return $preloaded['snapshot'];
        }
        $payload = json_encode(['x' => $position->x, 'z' => $position->z], JSON_THROW_ON_ERROR);
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

    public function saveEntityChunk(EntityChunkSnapshot $snapshot): void
    {
        $result = $this->request(
            WorldStorageOperation::SAVE_ENTITY_CHUNK,
            $this->entityPersistenceCodec->encode($snapshot),
            false,
        );
        if (($result->metadata['revision'] ?? null) !== $snapshot->chunkRevision || $result->payload !== '') {
            throw $this->failOwner('Entity snapshot save acknowledgement has the wrong revision.');
        }
        unset($this->preloadedEntityChunks[$snapshot->chunk->key()]);
    }

    public function transferEntityOwnership(EntityOwnershipTransfer $transfer): void
    {
        $result = $this->request(
            WorldStorageOperation::TRANSFER_ENTITY_OWNERSHIP,
            $this->entityOwnershipTransfers->encode($transfer),
            false,
        );
        if (($result->metadata['source_revision'] ?? null) !== $transfer->sourceAfter->chunkRevision
            || ($result->metadata['destination_revision'] ?? null) !== $transfer->destinationAfter->chunkRevision
            || $result->payload !== '') {
            throw $this->failOwner('Entity ownership transfer acknowledgement has the wrong revision.');
        }
        unset(
            $this->preloadedEntityChunks[$transfer->sourceAfter->chunk->key()],
            $this->preloadedEntityChunks[$transfer->destinationAfter->chunk->key()],
        );
    }

    /** Queues an immutable canonical snapshot without waiting for disk I/O. */
    public function enqueueChunkSave(ChunkSaveData $chunkData): PersistenceEnqueueResult
    {
        $this->assertOpen();

        return $this->writeQueue->enqueue(
            'chunk:' . $chunkData->chunk->position->key(),
            $chunkData->revision,
            $this->chunks->encode($chunkData->chunk, $this->blockStates),
        );
    }

    public static function chunkPositionFor(PersistenceWriteCompletion $completion): ChunkPosition
    {
        if (preg_match('/^chunk:(-?(?:0|[1-9][0-9]*)):(-?(?:0|[1-9][0-9]*))$/D', $completion->key, $matches) !== 1) {
            throw new \InvalidArgumentException('Persistence completion does not contain a chunk coordinate key.');
        }
        $position = new ChunkPosition((int) $matches[1], (int) $matches[2]);
        if ('chunk:' . $position->key() !== $completion->key) {
            throw new \InvalidArgumentException('Persistence completion chunk coordinate is not canonical.');
        }

        return $position;
    }

    /** Deduplicates a coordinate and returns false when it is already queued, in flight, or awaiting collection. */
    public function requestChunkLoad(ChunkPosition $position): bool
    {
        $this->assertOpen();
        $key = $position->key();
        if (isset($this->loadKeys[$key])) {
            return false;
        }
        if (count($this->loadKeys) >= 1_024) {
            throw new \OverflowException('Chunk load request queue is full.');
        }
        $this->loadKeys[$key] = true;
        $this->queuedLoads[$key] = $position;

        return true;
    }

    /**
     * @phpstan-impure Polling advances external storage work and consumes completed loads.
     * @return list<ChunkLoadCompletion>
     */
    public function pollChunkLoads(int $maximumCompletions = 256): array
    {
        $this->assertOpen();
        if ($maximumCompletions < 1 || $maximumCompletions > 256) {
            throw new \InvalidArgumentException('Chunk load completion limit is invalid.');
        }
        $this->advancePersistence();
        $completions = array_splice($this->loadCompletions, 0, $maximumCompletions);
        foreach ($completions as $completion) {
            unset($this->loadKeys[$completion->position->key()]);
        }

        return $completions;
    }

    /**
     * @phpstan-impure Polling advances external storage work and consumes completed writes.
     * @return list<PersistenceWriteCompletion>
     */
    public function pollChunkSaves(int $maximumCompletions = 256): array
    {
        $this->assertOpen();
        if ($maximumCompletions < 1 || $maximumCompletions > 256) {
            throw new \InvalidArgumentException('Chunk persistence completion limit is invalid.');
        }
        $this->advancePersistence();
        $completions = array_splice($this->deferredCompletions, 0, $maximumCompletions);
        while (count($completions) < $maximumCompletions) {
            $completion = $this->writeQueue->takeCompletion();
            if (!$completion instanceof PersistenceWriteCompletion) {
                break;
            }
            $completions[] = $completion;
        }

        return $completions;
    }

    /**
     * @phpstan-impure
     * @return list<PersistenceWriteCompletion>
     */
    public function drainChunkSaves(int $timeoutMilliseconds): array
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('Chunk persistence drain timeout is invalid.');
        }
        $all = [];
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        do {
            $all = [...$all, ...$this->pollChunkSaves()];
            $snapshot = $this->writeQueue->snapshot();
            if ($snapshot->queued === 0 && $snapshot->inFlight === 0
                && $this->asyncWrite === null && $this->asyncLoad === null
                && $this->asyncOutgoing === '' && $this->queuedLoads === []) {
                return $all;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        throw $this->failOwner('Timed out draining queued chunk persistence.');
    }

    public function close(): void
    {
        if ($this->closed) {
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

    private function launch(): void
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
        $connection = @stream_socket_accept($listener, 5);
        @fclose($listener);
        if (!is_resource($connection)) {
            @proc_terminate($process);
            throw new WorldStorageException('World storage process did not connect to its IPC endpoint.');
        }
        stream_set_blocking($connection, true);
        stream_set_timeout($connection, 5);
        $receivedToken = $this->readExact($connection, strlen($token));
        if (!is_string($receivedToken) || !hash_equals($token, $receivedToken)) {
            @fclose($connection);
            @proc_terminate($process);
            throw new WorldStorageException('World storage IPC authentication failed.');
        }
        $this->process = $process;
        $this->connection = $connection;
        $nonce = bin2hex(random_bytes(16));
        $this->writeFrame(new WorkerFrame(
            WorkerFrameKind::HELLO,
            $this->epoch,
            metadata: WorldStorageProcessProgram::identity($this->applicationVersion, $nonce),
            payload: (new WorldStorageStartupCodec())->encode($this->startup),
        ));
        $ready = $this->readFrame();
        if ($ready === null || !hash_equals($this->epoch, $ready->epoch)) {
            $this->closeProcess();
            throw new WorldStorageException('World storage process handshake failed.');
        }
        if ($ready->kind === WorkerFrameKind::FAILURE) {
            $this->closeProcess();
            throw self::mappedFailure(
                is_string($ready->metadata['code'] ?? null) ? $ready->metadata['code'] : 'storage_failure',
                $ready->metadata,
            );
        }
        if ($ready->kind !== WorkerFrameKind::READY
            || $ready->metadata !== WorldStorageProcessProgram::identity($this->applicationVersion, $nonce)) {
            $this->closeProcess();
            throw new WorldStorageException('World storage process identity did not match.');
        }
        try {
            $this->data = $this->worldDataCodec->decode($ready->payload);
        } catch (Throwable $error) {
            $this->closeProcess();
            throw new WorldStorageException('World storage process returned invalid world data.', previous: $error);
        }
        stream_set_timeout(
            $connection,
            intdiv($this->requestTimeoutMilliseconds, 1_000),
            ($this->requestTimeoutMilliseconds % 1_000) * 1_000,
        );
        stream_set_blocking($connection, false);
    }

    private function request(
        WorldStorageOperation $operation,
        string $payload,
        bool $drainQueuedPersistence = true,
    ): WorkerFrame {
        $this->assertOpen();
        $pending = $this->writeQueue->snapshot();
        if ($pending->queued > 0 || $pending->inFlight > 0 || $this->asyncWrite !== null
            || $this->asyncLoad !== null || $this->asyncOutgoing !== '' || $this->queuedLoads !== []) {
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
        while ($this->asyncWrite !== null || $this->asyncLoad !== null || $this->asyncOutgoing !== '') {
            $this->advancePersistence();
            if ($this->asyncWrite === null && $this->asyncLoad === null && $this->asyncOutgoing === '') {
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
        if ($this->asyncWrite === null && $this->asyncLoad === null) {
            $loadKey = array_key_first($this->queuedLoads);
            if (is_string($loadKey)) {
                $position = $this->queuedLoads[$loadKey];
                unset($this->queuedLoads[$loadKey]);
                $this->asyncLoad = $position;
                $this->asyncTaskId = $this->allocateTaskId();
                $this->asyncOutgoing = $this->frames->encode(new WorkerFrame(
                    WorkerFrameKind::SUBMIT,
                    $this->epoch,
                    $this->asyncTaskId,
                    WorldStorageOperation::LOAD_CHUNK->value,
                    WorldStorageProcessProgram::SCHEMA_VERSION,
                    payload: json_encode(['x' => $position->x, 'z' => $position->z], JSON_THROW_ON_ERROR),
                ));
            } else {
                $request = $this->writeQueue->dispatch();
                if ($request instanceof PersistenceWriteRequest) {
                    $this->asyncWrite = $request;
                    $this->asyncTaskId = $this->allocateTaskId();
                    $this->asyncOutgoing = $this->frames->encode(new WorkerFrame(
                        WorkerFrameKind::SUBMIT,
                        $this->epoch,
                        $this->asyncTaskId,
                        WorldStorageOperation::SAVE_CHUNK->value,
                        WorldStorageProcessProgram::SCHEMA_VERSION,
                        payload: $request->payload,
                    ));
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
            if ($this->asyncLoad instanceof ChunkPosition) {
                $this->acceptAsyncLoad($frame, $this->asyncLoad);
                $this->asyncLoad = null;
                $this->asyncTaskId = 0;
                if (count($frames) > 1) {
                    throw $this->failOwner('World storage returned more than one asynchronous completion.');
                }

                continue;
            }
            $request = $this->asyncWrite;
            if (!$request instanceof PersistenceWriteRequest
                || !hash_equals($this->epoch, $frame->epoch)
                || $frame->taskId !== $this->asyncTaskId
                || $frame->taskTypeId !== WorldStorageOperation::SAVE_CHUNK->value
                || $frame->schemaVersion !== WorldStorageProcessProgram::SCHEMA_VERSION) {
                throw $this->failOwner('World storage asynchronous response did not match its request.');
            }
            $successful = $frame->kind === WorkerFrameKind::RESULT
                && ($frame->metadata['revision'] ?? null) === $request->revision
                && $frame->payload === '';
            $failureCode = $successful
                ? null
                : (is_string($frame->metadata['code'] ?? null) ? $frame->metadata['code'] : 'storage_failure');
            $this->writeQueue->complete(
                $request->id,
                $request->key,
                $request->revision,
                $successful,
                $failureCode,
            );
            $this->asyncWrite = null;
            $this->asyncTaskId = 0;
            if (count($frames) > 1) {
                throw $this->failOwner('World storage returned more than one asynchronous completion.');
            }
        }
    }

    private function acceptAsyncLoad(WorkerFrame $frame, ChunkPosition $position): void
    {
        if (!hash_equals($this->epoch, $frame->epoch) || $frame->taskId !== $this->asyncTaskId
            || $frame->taskTypeId !== WorldStorageOperation::LOAD_CHUNK->value
            || $frame->schemaVersion !== WorldStorageProcessProgram::SCHEMA_VERSION) {
            throw $this->failOwner('World storage chunk-load response did not match its request.');
        }
        if ($frame->kind === WorkerFrameKind::FAILURE) {
            $code = is_string($frame->metadata['code'] ?? null) ? $frame->metadata['code'] : 'storage_failure';
            $this->loadCompletions[] = new ChunkLoadCompletion($position, null, false, $code);

            return;
        }
        if ($frame->kind !== WorkerFrameKind::RESULT) {
            throw $this->failOwner('World storage chunk-load response kind is invalid.');
        }
        if (($frame->metadata['missing'] ?? null) === true && $frame->payload === '') {
            $this->rememberPreloadedEntityChunk($position, 'missing');
            $this->loadCompletions[] = new ChunkLoadCompletion($position, null, true);

            return;
        }
        if (($frame->metadata['missing'] ?? null) !== false || !is_bool($frame->metadata['upgraded'] ?? null)) {
            throw $this->failOwner('World storage chunk-load response metadata is invalid.');
        }
        try {
            $chunk = $this->decodeLoadedChunkPayload($position, $frame->payload, $frame->metadata['entity_state'] ?? null);
        } catch (Throwable $error) {
            throw $this->failOwner('World storage chunk-load response payload is invalid.', $error);
        }
        $this->loadCompletions[] = new ChunkLoadCompletion(
            $position,
            new LoadedChunkData($chunk, $frame->metadata['upgraded']),
            false,
        );
    }

    /** @param bool|int|string|null $entityState */
    private function decodeLoadedChunkPayload(ChunkPosition $position, string $payload, bool|int|string|null $entityState): \Bedriox\Server\World\Chunk
    {
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
        $this->rememberPreloadedEntityChunk($position, $entityState, $entitySnapshot);

        return $chunk;
    }

    /** @param 'loaded'|'missing'|'corrupt' $state */
    private function rememberPreloadedEntityChunk(
        ChunkPosition $position,
        string $state,
        ?EntityChunkSnapshot $snapshot = null,
    ): void {
        $key = $position->key();
        unset($this->preloadedEntityChunks[$key]);
        $this->preloadedEntityChunks[$key] = ['state' => $state, 'snapshot' => $snapshot];
        while (count($this->preloadedEntityChunks) > self::MAXIMUM_PRELOADED_ENTITY_CHUNKS) {
            $oldest = array_key_first($this->preloadedEntityChunks);
            unset($this->preloadedEntityChunks[$oldest]);
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed || !is_resource($this->process) || !is_resource($this->connection)) {
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
                'World storage owner reported a stale entity persistence operation.',
            ),
            'unsupported_world_format' => new UnsupportedWorldFormatException('World storage owner reported an unsupported format.'),
            'provider_closed' => new WorldProviderClosedException('World storage owner is closed.'),
            default => new WorldStorageException('World storage owner reported an I/O failure.'),
        };
    }

    private function closeProcess(): void
    {
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
