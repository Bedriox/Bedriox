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

namespace Bedriox\Server\Persistence\World\Internal;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferCodec;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResultCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
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
    public const int SCHEMA_VERSION = 3;

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
        $entityTransferResults = new EntityOwnershipTransferResultCodec($entities);
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
                        $entityTransferResults,
                    ),
                    WorldStorageOperation::LOAD_TRANSIENT_ENTITIES => self::loadTransientEntities(
                        $provider,
                        $frame->payload,
                    ),
                    WorldStorageOperation::SAVE_TRANSIENT_ENTITIES => self::saveTransientEntities(
                        $provider,
                        $frame->payload,
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
        [$dimension, $positionPayload] = self::decodeDimensionPayload($payload);
        $position = self::decodePosition($positionPayload);
        $loaded = $provider->loadChunk($position, $dimension);
        if ($loaded === null) {
            return [['missing' => true, 'upgraded' => false], ''];
        }

        $entityState = 'missing';
        $entityPayload = null;
        if ($provider instanceof EntityPersistenceStore) {
            try {
                $snapshot = $provider->loadEntityChunk($position, $dimension);
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
        [$dimension, $chunkPayload] = self::decodeDimensionPayload($payload);
        $chunk = $codec->decode($chunkPayload, $states);
        $provider->saveChunk(new ChunkSaveData($chunk), $dimension);

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
        [$dimension, $positionPayload] = self::decodeDimensionPayload($payload);
        $position = self::decodePosition($positionPayload);
        $snapshot = $provider->loadEntityChunk($position, $dimension);
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
        [$dimension, $snapshotPayload] = self::decodeDimensionPayload($payload);
        $snapshot = $codec->decode($snapshotPayload);
        $provider->saveEntityChunk($snapshot, $dimension);

        return [['revision' => $snapshot->chunkRevision], ''];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function transferEntityOwnership(
        WritableWorldProvider $provider,
        string $payload,
        EntityOwnershipTransferCodec $codec,
        EntityOwnershipTransferResultCodec $results,
    ): array {
        if (!$provider instanceof EntityPersistenceStore) {
            throw new \RuntimeException('World storage provider does not support entity persistence.');
        }
        [$dimension, $transferPayload] = self::decodeDimensionPayload($payload);
        $transfer = $codec->decode($transferPayload);
        $result = $provider->transferEntityOwnership($transfer, $dimension);

        return [[
            'source_revision' => $result->sourceAfter->chunkRevision,
            'destination_revision' => $result->destinationAfter->chunkRevision,
        ], $results->encode($result)];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function loadTransientEntities(WritableWorldProvider $provider, string $namespace): array
    {
        if (!$provider instanceof TransientEntityPersistenceStore) {
            throw new \RuntimeException('World storage provider does not support transient entity persistence.');
        }
        self::validateTransientNamespace($namespace);
        $payload = $provider->loadTransientEntities($namespace);

        return $payload === null ? [['missing' => true], ''] : [['missing' => false], $payload];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function saveTransientEntities(WritableWorldProvider $provider, string $request): array
    {
        if (!$provider instanceof TransientEntityPersistenceStore) {
            throw new \RuntimeException('World storage provider does not support transient entity persistence.');
        }
        if (strlen($request) < 3) {
            throw new \InvalidArgumentException('Transient entity save request is truncated.');
        }
        $namespaceLength = ord($request[0]);
        if ($namespaceLength < 1 || strlen($request) < $namespaceLength + 2) {
            throw new \InvalidArgumentException('Transient entity save request is malformed.');
        }
        $namespace = substr($request, 1, $namespaceLength);
        self::validateTransientNamespace($namespace);
        $present = ord($request[$namespaceLength + 1]);
        if ($present !== 0 && $present !== 1) {
            throw new \InvalidArgumentException('Transient entity save request has an invalid presence marker.');
        }
        $payload = substr($request, $namespaceLength + 2);
        if ($present === 0 && $payload !== '') {
            throw new \InvalidArgumentException('Deleted transient entity state unexpectedly contained data.');
        }
        $provider->saveTransientEntities($namespace, $present === 0 ? null : $payload);

        return [[], ''];
    }

    private static function validateTransientNamespace(string $namespace): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('Transient entity namespace is invalid.');
        }
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

    /** @return array{WorldDimension, string} */
    private static function decodeDimensionPayload(string $payload): array
    {
        if ($payload === '') {
            throw new \InvalidArgumentException('Dimension-aware world storage payload is empty.');
        }
        $dimension = match (ord($payload[0])) {
            0 => WorldDimension::OVERWORLD,
            1 => WorldDimension::NETHER,
            2 => WorldDimension::END,
            default => throw new \InvalidArgumentException('World storage dimension is invalid.'),
        };

        return [$dimension, substr($payload, 1)];
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
        $metadata = [
            'code' => self::failureCode($error),
            'detail' => substr($error->getMessage(), 0, 512),
        ];
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
