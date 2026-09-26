<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World\Internal;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Persistence\World\WorldDataIpcCodec;
use Bedriox\Server\Persistence\World\WorldStorageOperation;
use Bedriox\Server\Persistence\World\WorldStorageStartupCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Provider\Exception\UnsupportedWorldFormatException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\LevelDbWorldProvider;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Closure;
use Throwable;

final class WorldStorageProcessProgram
{
    public const int SCHEMA_VERSION = 1;

    public static function run(
        string $epochHex,
        string $applicationVersion,
        string $endpoint,
        string $tokenHex,
        ?Closure $providerFactory = null,
    ): int {
        $epoch = hex2bin($epochHex);
        $token = hex2bin($tokenHex);
        if (!is_string($epoch) || strlen($epoch) !== 16 || !is_string($token) || strlen($token) !== 32
            || $applicationVersion === '' || strlen($applicationVersion) > 128) {
            return 64;
        }
        $connection = @stream_socket_client($endpoint, $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT);
        if (!is_resource($connection)) {
            return 69;
        }
        stream_set_blocking($connection, true);
        self::write($connection, $token);
        $codec = new WorkerFrameCodec();
        $hello = self::readFrame($connection, $codec);
        $nonce = $hello?->metadata['nonce'] ?? null;
        if ($hello === null || $hello->kind !== WorkerFrameKind::HELLO || !hash_equals($epoch, $hello->epoch)
            || !is_string($nonce) || strlen($nonce) !== 32
            || $hello->metadata !== self::identity($applicationVersion, $nonce)) {
            return 65;
        }

        $provider = null;
        try {
            $startup = (new WorldStorageStartupCodec())->decode($hello->payload);
            $data = BedrockDataSet::bundled();
            $states = new BlockStateRegistry($data->blockStateRegistry()->states());
            $persistentStates = $data->persistentBlockStateRegistry();
            $provider = $providerFactory === null
                ? self::openProvider($startup, $states, $persistentStates)
                : $providerFactory($startup, $states, $persistentStates);
            if (!$provider instanceof WritableWorldProvider) {
                throw new \RuntimeException('World storage provider factory returned an invalid owner.');
            }
            self::write($connection, $codec->encode(new WorkerFrame(
                WorkerFrameKind::READY,
                $epoch,
                metadata: self::identity($applicationVersion, $nonce),
                payload: (new WorldDataIpcCodec())->encode($provider->worldData()),
            )));
            self::serve($connection, $codec, $epoch, $provider, $states);

            return 0;
        } catch (Throwable $error) {
            if (!$provider instanceof WritableWorldProvider) {
                self::write($connection, $codec->encode(new WorkerFrame(
                    WorkerFrameKind::FAILURE,
                    $epoch,
                    metadata: self::failureMetadata($error),
                )));
            }
            try {
                if ($provider instanceof WritableWorldProvider) {
                    $provider->close();
                }
            } catch (Throwable) {
            }

            return 74;
        }
    }

    private static function openProvider(
        \Bedriox\Server\Persistence\World\WorldStorageStartup $startup,
        BlockStateRegistry $states,
        PersistentBlockStateRegistry $persistentStates,
    ): WritableWorldProvider {
        return $startup->mode === 'open'
            ? LevelDbWorldProvider::open($startup->path, $states, $persistentStates)
            : LevelDbWorldProvider::create(
                $startup->path,
                $startup->createData ?? throw new \LogicException('Create startup data is missing.'),
                $states,
                $persistentStates,
                createdAt: $startup->createdAt,
            );
    }

    /** @return array<string, string> */
    public static function identity(string $applicationVersion, string $nonce): array
    {
        return [
            'application_version' => $applicationVersion,
            'nonce' => $nonce,
            'service' => 'world-storage',
        ];
    }

    /** @param resource $connection */
    private static function serve(
        $connection,
        WorkerFrameCodec $codec,
        string $epoch,
        WritableWorldProvider $provider,
        BlockStateRegistry $states,
    ): void {
        $chunks = new ChunkTransferCodec();
        $worldData = new WorldDataIpcCodec();
        $entities = EntityPersistenceCodec::vanilla();
        $entityTransfers = new EntityOwnershipTransferCodec($entities);
        while (($frame = self::readFrame($connection, $codec)) !== null) {
            if (!hash_equals($epoch, $frame->epoch)) {
                throw new \RuntimeException('World storage epoch changed.');
            }
            if ($frame->kind === WorkerFrameKind::SHUTDOWN) {
                $provider->close();
                self::write($connection, $codec->encode(new WorkerFrame(WorkerFrameKind::RESULT, $epoch)));

                return;
            }
            $operation = WorldStorageOperation::tryFrom($frame->taskTypeId);
            if ($frame->kind !== WorkerFrameKind::SUBMIT || $operation === null
                || $frame->schemaVersion !== self::SCHEMA_VERSION) {
                throw new \RuntimeException('World storage request is invalid.');
            }
            try {
                [$metadata, $payload] = match ($operation) {
                    WorldStorageOperation::LOAD_CHUNK => self::loadChunk(
                        $provider,
                        $frame->payload,
                        $chunks,
                        $entities,
                        $states,
                    ),
                    WorldStorageOperation::SAVE_CHUNK => self::saveChunk($provider, $frame->payload, $chunks, $states),
                    WorldStorageOperation::SAVE_WORLD_DATA => self::saveWorldData($provider, $frame->payload, $worldData),
                    WorldStorageOperation::LOAD_ENTITY_CHUNK => self::loadEntityChunk($provider, $frame->payload, $entities),
                    WorldStorageOperation::SAVE_ENTITY_CHUNK => self::saveEntityChunk($provider, $frame->payload, $entities),
                    WorldStorageOperation::TRANSFER_ENTITY_OWNERSHIP => self::transferEntityOwnership(
                        $provider,
                        $frame->payload,
                        $entityTransfers,
                    ),
                };
                self::write($connection, $codec->encode(new WorkerFrame(
                    WorkerFrameKind::RESULT,
                    $epoch,
                    $frame->taskId,
                    $operation->value,
                    self::SCHEMA_VERSION,
                    metadata: $metadata,
                    payload: $payload,
                )));
            } catch (Throwable $error) {
                self::write($connection, $codec->encode(new WorkerFrame(
                    WorkerFrameKind::FAILURE,
                    $epoch,
                    $frame->taskId,
                    $operation->value,
                    self::SCHEMA_VERSION,
                    metadata: self::failureMetadata($error),
                )));
            }
        }
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function loadChunk(
        WritableWorldProvider $provider,
        string $payload,
        ChunkTransferCodec $codec,
        EntityPersistenceCodec $entities,
        BlockStateRegistry $states,
    ): array {
        $position = self::decodePosition($payload);
        $loaded = $provider->loadChunk($position);
        if ($loaded === null) {
            return [['missing' => true, 'upgraded' => false], ''];
        }

        $entityState = 'missing';
        $entityPayload = null;
        if ($provider instanceof EntityPersistenceStore) {
            try {
                $snapshot = $provider->loadEntityChunk($position);
                if ($snapshot !== null) {
                    $entityState = 'loaded';
                    $entityPayload = $entities->encode($snapshot);
                }
            } catch (CorruptEntityPersistenceException) {
                $entityState = 'corrupt';
            }
        }
        $framed = (new \Bedriox\Server\Persistence\World\WorldChunkLoadPayloadCodec())->encode(
            $codec->encode($loaded->chunk, $states),
            $entityPayload,
        );

        return [[
            'missing' => false,
            'upgraded' => $loaded->upgraded,
            'entity_state' => $entityState,
        ], $framed];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function saveChunk(
        WritableWorldProvider $provider,
        string $payload,
        ChunkTransferCodec $codec,
        BlockStateRegistry $states,
    ): array {
        $chunk = $codec->decode($payload, $states);
        $provider->saveChunk(new ChunkSaveData($chunk));

        return [['revision' => $chunk->revision], ''];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function saveWorldData(
        WritableWorldProvider $provider,
        string $payload,
        WorldDataIpcCodec $codec,
    ): array {
        $provider->saveWorldData($codec->decode($payload));

        return [[], ''];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function loadEntityChunk(
        WritableWorldProvider $provider,
        string $payload,
        EntityPersistenceCodec $codec,
    ): array {
        if (!$provider instanceof EntityPersistenceStore) {
            throw new \RuntimeException('World storage provider does not support entity persistence.');
        }
        $position = self::decodePosition($payload);
        $snapshot = $provider->loadEntityChunk($position);
        if ($snapshot === null) {
            return [['missing' => true], ''];
        }

        return [['missing' => false], $codec->encode($snapshot)];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function saveEntityChunk(
        WritableWorldProvider $provider,
        string $payload,
        EntityPersistenceCodec $codec,
    ): array {
        if (!$provider instanceof EntityPersistenceStore) {
            throw new \RuntimeException('World storage provider does not support entity persistence.');
        }
        $snapshot = $codec->decode($payload);
        $provider->saveEntityChunk($snapshot);

        return [['revision' => $snapshot->chunkRevision], ''];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function transferEntityOwnership(
        WritableWorldProvider $provider,
        string $payload,
        EntityOwnershipTransferCodec $codec,
    ): array {
        if (!$provider instanceof EntityPersistenceStore) {
            throw new \RuntimeException('World storage provider does not support entity persistence.');
        }
        $transfer = $codec->decode($payload);
        $provider->transferEntityOwnership($transfer);

        return [[
            'source_revision' => $transfer->sourceAfter->chunkRevision,
            'destination_revision' => $transfer->destinationAfter->chunkRevision,
        ], ''];
    }

    private static function decodePosition(string $payload): ChunkPosition
    {
        if (strlen($payload) > 128) {
            throw new \InvalidArgumentException('Chunk position request exceeds its size limit.');
        }
        $value = json_decode($payload, true, 2, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_keys($value) !== ['x', 'z'] || !is_int($value['x']) || !is_int($value['z'])) {
            throw new \InvalidArgumentException('Chunk position request is malformed.');
        }

        return new ChunkPosition($value['x'], $value['z']);
    }

    private static function failureCode(Throwable $error): string
    {
        return match (true) {
            $error instanceof CorruptChunkException => 'corrupt_chunk',
            $error instanceof CorruptWorldDataException => 'corrupt_world_data',
            $error instanceof CorruptEntityPersistenceException => 'corrupt_entity_persistence',
            $error instanceof EntityPersistenceConflictException => 'entity_persistence_conflict',
            $error instanceof UnsupportedWorldFormatException => 'unsupported_world_format',
            $error instanceof WorldProviderClosedException => 'provider_closed',
            default => 'storage_failure',
        };
    }

    /** @return array<string, bool|int|string|null> */
    private static function failureMetadata(Throwable $error): array
    {
        $metadata = ['code' => self::failureCode($error)];
        if ($error instanceof CorruptEntityPersistenceException) {
            $metadata['chunk_x'] = $error->chunk->x;
            $metadata['chunk_z'] = $error->chunk->z;
        }

        return $metadata;
    }

    /** @param resource $stream */
    private static function readFrame($stream, WorkerFrameCodec $codec): ?WorkerFrame
    {
        $header = self::readExact($stream, WorkerFrameCodec::FIXED_HEADER_BYTES);
        if ($header === null) {
            return null;
        }
        $lengths = $codec->lengths($header);
        if ($lengths === null) {
            return null;
        }
        $body = self::readExact($stream, $lengths['headerLength'] + $lengths['payloadLength']);

        return $body === null ? null : $codec->decode($header . $body);
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        if ($length === 0) {
            return '';
        }
        $bytes = '';
        while (strlen($bytes) < $length && !feof($stream)) {
            $chunk = @fread($stream, max(1, $length - strlen($bytes)));
            if ($chunk === false) {
                $metadata = stream_get_meta_data($stream);
                if ($metadata['timed_out']) {
                    continue;
                }

                return null;
            }
            if ($chunk === '') {
                usleep(1_000);
                continue;
            }
            $bytes .= $chunk;
        }

        return strlen($bytes) === $length ? $bytes : null;
    }

    /** @param resource $stream */
    private static function write($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if (!is_int($written) || $written < 1) {
                throw new \RuntimeException('World storage IPC write failed.');
            }
            $bytes = substr($bytes, $written);
        }
        fflush($stream);
    }
}
