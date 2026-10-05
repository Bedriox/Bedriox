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

namespace Bedriox\Server\Worker\Chunk;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use InvalidArgumentException;
use WeakMap;

/** Shared, bounded LRU of revision-specific compressed LevelChunk envelopes. */
final class PreparedChunkCache
{
    private const int MAXIMUM_FAILURES_BEFORE_FALLBACK = 3;
    private const int MAXIMUM_RESULT_BYTES = 1_048_577;

    /** @var array<string, array{chunk: PreparedChunk, lastAccess: int}> */
    private array $entries = [];
    /** @var array<string, array{receipt: WorkerReceipt, bytes: int, position: string}> */
    private array $pending = [];
    /** @var array<string, string> position key => current complete cache key */
    private array $currentKeys = [];
    /** @var array<string, int> */
    private array $failedAttempts = [];
    /** @var WeakMap<Chunk, int> */
    private WeakMap $snapshotIds;

    private int $nextSnapshotId = 0;
    private int $accessSequence = 0;
    private int $entryBytes = 0;
    private int $pendingBytes = 0;
    private int $hits = 0;
    private int $misses = 0;
    private int $evictions = 0;
    private int $invalidations = 0;
    private int $failures = 0;
    private bool $closed = false;
    private readonly string $registryHash;

    public function __construct(
        private readonly WorkerDispatcher $workers,
        private readonly int $taskTypeId,
        private readonly BlockStateRegistry $states,
        private readonly string $worldIncarnation,
        private readonly int $maximumEntries = 4_096,
        private readonly int $maximumBytes = 67_108_864,
        private readonly int $maximumPending = 256,
        private readonly int $maximumPendingBytes = 67_108_864,
        ?string $registryHash = null,
        private readonly WorldDimension $dimension = WorldDimension::OVERWORLD,
    ) {
        $registryHash ??= ChunkProjectionIdentity::bundledRegistryHash();
        if ($taskTypeId < 1 || $taskTypeId > 65_535
            || preg_match('/^[a-f0-9]{32}$/D', $worldIncarnation) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $registryHash) !== 1
            || $maximumEntries < 1 || $maximumEntries > 65_536
            || $maximumBytes < 1 || $maximumBytes > 536_870_912
            || $maximumPending < 1 || $maximumPending > 4_096
            || $maximumPendingBytes < 1 || $maximumPendingBytes > 536_870_912) {
            throw new InvalidArgumentException('Prepared chunk cache limits or identity are invalid.');
        }
        $this->snapshotIds = new WeakMap();
        $this->registryHash = $registryHash;
    }

    public function lookupOrRequest(Chunk $chunk, int $protocolVersion): PreparedChunkLookup
    {
        if ($this->closed) {
            return new PreparedChunkLookup(PreparedChunkAvailability::SYNCHRONOUS_FALLBACK);
        }

        $positionKey = $chunk->position->key();
        $cacheKey = $this->cacheKey($chunk, $protocolVersion);
        $this->makeCurrent($positionKey, $cacheKey);
        $entry = $this->entries[$cacheKey] ?? null;
        if ($entry !== null) {
            ++$this->hits;
            $this->entries[$cacheKey]['lastAccess'] = ++$this->accessSequence;

            return new PreparedChunkLookup(PreparedChunkAvailability::READY, $entry['chunk']);
        }
        ++$this->misses;
        if (isset($this->pending[$cacheKey])) {
            return new PreparedChunkLookup(PreparedChunkAvailability::PENDING);
        }
        if (($this->failedAttempts[$cacheKey] ?? 0) >= self::MAXIMUM_FAILURES_BEFORE_FALLBACK) {
            return new PreparedChunkLookup(PreparedChunkAvailability::SYNCHRONOUS_FALLBACK);
        }
        if (count($this->pending) >= $this->maximumPending) {
            return new PreparedChunkLookup(PreparedChunkAvailability::DEFERRED);
        }

        try {
            $chunkTransfer = (new ChunkProjectionTransferCodec())->encode($chunk, $this->states, $this->dimension);
            $request = (new ChunkPreparationRequestCodec())->encode(new ChunkPreparationRequest(
                $protocolVersion,
                ChunkProjectionIdentity::SERIALIZER_VERSION,
                ChunkProjectionIdentity::COMPRESSION_THRESHOLD,
                $this->registryHash,
                $chunkTransfer,
            ));
        } catch (\Throwable) {
            $this->failedAttempts[$cacheKey] = self::MAXIMUM_FAILURES_BEFORE_FALLBACK;
            ++$this->failures;

            return new PreparedChunkLookup(PreparedChunkAvailability::SYNCHRONOUS_FALLBACK);
        }
        $requestBytes = strlen($request);
        if ($requestBytes > $this->maximumPendingBytes - $this->pendingBytes) {
            return new PreparedChunkLookup(PreparedChunkAvailability::DEFERRED);
        }

        $position = $chunk->position;
        $revision = $chunk->revision;
        $submission = $this->workers->submit(
            $this->taskTypeId,
            $request,
            function (WorkerResult $result) use ($cacheKey, $positionKey, $position, $revision, $protocolVersion): void {
                $pending = $this->pending[$cacheKey] ?? null;
                if ($pending === null || $pending['receipt']->taskId !== $result->receipt->taskId) {
                    return;
                }
                unset($this->pending[$cacheKey]);
                $this->pendingBytes -= $pending['bytes'];
                if ($this->closed || ($this->currentKeys[$positionKey] ?? null) !== $cacheKey) {
                    return;
                }
                if ($result->status !== WorkerResultStatus::SUCCESS
                    || !$this->isValidEnvelope($result->payload)) {
                    $this->recordFailure($cacheKey);

                    return;
                }

                $prepared = new PreparedChunk($cacheKey, $position, $revision, $protocolVersion, $result->payload);
                if ($prepared->bytes() > $this->maximumBytes) {
                    $this->failedAttempts[$cacheKey] = self::MAXIMUM_FAILURES_BEFORE_FALLBACK;
                    ++$this->failures;

                    return;
                }
                $this->evictFor($prepared->bytes());
                $this->entries[$cacheKey] = [
                    'chunk' => $prepared,
                    'lastAccess' => ++$this->accessSequence,
                ];
                $this->entryBytes += $prepared->bytes();
                unset($this->failedAttempts[$cacheKey]);
            },
        );
        if ($submission->receipt === null) {
            return new PreparedChunkLookup(PreparedChunkAvailability::DEFERRED);
        }
        $this->pending[$cacheKey] = [
            'receipt' => $submission->receipt,
            'bytes' => $requestBytes,
            'position' => $positionKey,
        ];
        $this->pendingBytes += $requestBytes;

        return new PreparedChunkLookup(PreparedChunkAvailability::PENDING, submittedBytes: $requestBytes);
    }

    public function isCurrent(PreparedChunk $prepared, Chunk $chunk, int $protocolVersion): bool
    {
        return !$this->closed
            && $prepared->protocolVersion === $protocolVersion
            && $prepared->revision === $chunk->revision
            && $prepared->position->key() === $chunk->position->key()
            && hash_equals($prepared->cacheKey, $this->cacheKey($chunk, $protocolVersion));
    }

    /** Retains a main-process fallback projection so every later viewer reuses it. */
    public function retainSynchronous(Chunk $chunk, int $protocolVersion, string $clearEnvelope): PreparedChunk
    {
        if ($this->closed || !$this->isValidEnvelope($clearEnvelope)) {
            throw new InvalidArgumentException('Synchronous chunk projection is invalid.');
        }
        $positionKey = $chunk->position->key();
        $cacheKey = $this->cacheKey($chunk, $protocolVersion);
        $this->makeCurrent($positionKey, $cacheKey);
        $existing = $this->entries[$cacheKey]['chunk'] ?? null;
        if ($existing instanceof PreparedChunk) {
            $this->entries[$cacheKey]['lastAccess'] = ++$this->accessSequence;

            return $existing;
        }
        $pending = $this->pending[$cacheKey] ?? null;
        if ($pending !== null) {
            $this->workers->cancel($pending['receipt']);
            $this->pendingBytes -= $pending['bytes'];
            unset($this->pending[$cacheKey]);
        }

        $prepared = new PreparedChunk(
            $cacheKey,
            $chunk->position,
            $chunk->revision,
            $protocolVersion,
            $clearEnvelope,
        );
        if ($prepared->bytes() > $this->maximumBytes) {
            throw new InvalidArgumentException('Synchronous chunk projection exceeds the cache byte limit.');
        }
        $this->evictFor($prepared->bytes());
        $this->entries[$cacheKey] = [
            'chunk' => $prepared,
            'lastAccess' => ++$this->accessSequence,
        ];
        $this->entryBytes += $prepared->bytes();
        unset($this->failedAttempts[$cacheKey]);

        return $prepared;
    }

    public function snapshot(): PreparedChunkCacheSnapshot
    {
        return new PreparedChunkCacheSnapshot(
            count($this->entries),
            $this->entryBytes,
            count($this->pending),
            $this->pendingBytes,
            $this->hits,
            $this->misses,
            $this->evictions,
            $this->invalidations,
            $this->failures,
        );
    }

    /**
     * Releases disposable completed envelopes and optionally cancels pending preparation work.
     *
     * Authoritative chunk state is never stored here, so pressure cleanup can safely rebuild
     * every removed entry on demand.
     */
    public function trim(bool $cancelPending = false): int
    {
        $released = $this->entryBytes;
        $this->evictions += count($this->entries);
        $this->entries = [];
        $this->entryBytes = 0;
        $this->failedAttempts = [];

        if ($cancelPending) {
            $released += $this->pendingBytes;
            foreach ($this->pending as $pending) {
                $this->workers->cancel($pending['receipt']);
            }
            $this->pending = [];
            $this->pendingBytes = 0;
            $this->currentKeys = [];
        }

        return $released;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->pending as $pending) {
            $this->workers->cancel($pending['receipt']);
        }
        $this->entries = [];
        $this->pending = [];
        $this->currentKeys = [];
        $this->failedAttempts = [];
        $this->entryBytes = 0;
        $this->pendingBytes = 0;
    }

    private function cacheKey(Chunk $chunk, int $protocolVersion): string
    {
        if ($protocolVersion < 1) {
            throw new InvalidArgumentException('Prepared chunk protocol version must be positive.');
        }
        $snapshotId = $this->snapshotIds[$chunk] ?? null;
        if (!is_int($snapshotId)) {
            $snapshotId = ++$this->nextSnapshotId;
            $this->snapshotIds[$chunk] = $snapshotId;
        }

        return hash('sha256', implode('|', [
            $this->worldIncarnation,
            $this->dimension->value,
            $chunk->position->x,
            $chunk->position->z,
            $chunk->revision,
            $snapshotId,
            $protocolVersion,
            $this->registryHash,
            ChunkProjectionIdentity::SERIALIZER_VERSION,
            ChunkProjectionIdentity::COMPRESSION_PROFILE,
        ]));
    }

    private function makeCurrent(string $positionKey, string $cacheKey): void
    {
        $previous = $this->currentKeys[$positionKey] ?? null;
        if ($previous === null || hash_equals($previous, $cacheKey)) {
            $this->currentKeys[$positionKey] = $cacheKey;

            return;
        }
        $entry = $this->entries[$previous]['chunk'] ?? null;
        if ($entry instanceof PreparedChunk) {
            $this->entryBytes -= $entry->bytes();
            unset($this->entries[$previous]);
        }
        $pending = $this->pending[$previous] ?? null;
        if ($pending !== null) {
            $this->workers->cancel($pending['receipt']);
            $this->pendingBytes -= $pending['bytes'];
            unset($this->pending[$previous]);
        }
        unset($this->failedAttempts[$previous]);
        $this->currentKeys[$positionKey] = $cacheKey;
        ++$this->invalidations;
    }

    private function recordFailure(string $cacheKey): void
    {
        $this->failedAttempts[$cacheKey] = min(
            self::MAXIMUM_FAILURES_BEFORE_FALLBACK,
            ($this->failedAttempts[$cacheKey] ?? 0) + 1,
        );
        ++$this->failures;
    }

    private function isValidEnvelope(string $payload): bool
    {
        return $payload !== '' && strlen($payload) <= self::MAXIMUM_RESULT_BYTES
            && ord($payload[0]) === BedrockBatchCodec::GAME_PACKET_MARKER;
    }

    private function evictFor(int $incomingBytes): void
    {
        while ($this->entries !== [] && (count($this->entries) >= $this->maximumEntries
            || $incomingBytes > $this->maximumBytes - $this->entryBytes)) {
            $candidate = null;
            $oldest = PHP_INT_MAX;
            foreach ($this->entries as $key => $entry) {
                if ($entry['lastAccess'] < $oldest) {
                    $candidate = $key;
                    $oldest = $entry['lastAccess'];
                }
            }
            if ($candidate === null) {
                break;
            }
            $removed = $this->entries[$candidate]['chunk'];
            $this->entryBytes -= $removed->bytes();
            unset($this->entries[$candidate]);
            ++$this->evictions;
        }
    }
}
