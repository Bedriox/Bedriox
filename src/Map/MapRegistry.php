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

namespace Bedriox\Server\Map;

use Bedriox\Api\Map\MapImage;
use Bedriox\Api\Map\MapScale;
use Bedriox\Api\World\DimensionType;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\BlockPosition;
use Bedriox\Protocol\Packet\ClientboundMapItemDataPacket;
use Bedriox\Protocol\Packet\MapPixel;
use Bedriox\Protocol\Packet\PackedMapColors;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use RuntimeException;

/** Persistent bounded map records and world-owned incremental map work. */
final class MapRegistry
{
    private const int MAXIMUM_MAPS = 65_536;
    private const int MAXIMUM_CACHE_BYTES = 67_108_864;
    private const int MAXIMUM_PROJECTION_BYTES = 134_217_728;
    private const int MAXIMUM_PROJECTIONS = 1_024;
    private const int TERRAIN_PIXELS_PER_SLICE = 64;
    private const int PERSISTENCE_REVISION_INTERVAL = 16;
    private const int PERSISTENCE_TICK_INTERVAL = 200;

    /** @var array<int, MapRecord> */
    private array $cache = [];
    /** @var array<int, int> */
    private array $cacheUse = [];
    private int $cacheBytes = 0;
    private int $usageSequence = 0;
    private int $nextId;
    private bool $indexDirty = false;

    /** @var array<int, MapRecord> */
    private array $dirty = [];
    /** @var array<int, int> Map ID to the last revision durably written. */
    private array $persistedRevisions = [];
    /** @var array<int, int> Map ID to the tick when its current dirty interval began. */
    private array $dirtySinceTicks = [];
    private int $tickSequence = 0;
    /** @var array<string, MapTerrainSampler> */
    private array $terrainSamplers = [];
    /** @var array<int, array<string, true>> */
    private array $terrainSubscribers = [];
    /** @var array<int, int> */
    private array $terrainCursors = [];
    /** @var list<int> */
    private array $terrainOrder = [];
    private int $terrainOrderCursor = 0;
    /** @var array<int, MapPixelDelta> */
    private array $latestDeltas = [];
    /** @var array<int, array<int, MapPixel>> Map ID to pixel-indexed provisional client samples. */
    private array $clientHints = [];
    private int $clientHintCount = 0;

    /** @var array<string, PreparedMapProjection> */
    private array $projections = [];
    /** @var array<string, int> */
    private array $projectionUse = [];
    private int $projectionBytes = 0;

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the world map directory.');
        }
        $index = $directory . DIRECTORY_SEPARATOR . 'index.json';
        $decoded = is_file($index) ? json_decode((string) file_get_contents($index), true) : null;
        $next = is_array($decoded) ? ($decoded['nextId'] ?? null) : null;
        $this->nextId = is_int($next) && $next >= 1 && $next <= self::MAXIMUM_MAPS + 1 ? $next : 1;
    }

    public function createImage(DimensionType $dimension, MapImage $image): MapRecord
    {
        if ($image->width !== MapRecord::WIDTH || $image->height !== MapRecord::HEIGHT) {
            throw new \InvalidArgumentException('A persisted map image must be exactly 128 by 128 pixels.');
        }

        return $this->create($dimension, 0, 0, MapScale::CLOSEST, true, false, $image->rgba());
    }

    public function createTerrain(
        DimensionType $dimension,
        int $centerX,
        int $centerZ,
        MapScale $scale,
        ?string $rgba = null,
    ): MapRecord {
        return $this->create(
            $dimension,
            $centerX,
            $centerZ,
            $scale,
            false,
            true,
            $rgba ?? str_repeat("\0", MapRecord::PIXEL_BYTES),
        );
    }

    public function get(int $id): ?MapRecord
    {
        if (!$this->isKnownId($id)) {
            return null;
        }
        if (isset($this->cache[$id])) {
            $this->cacheUse[$id] = ++$this->usageSequence;

            return $this->cache[$id];
        }
        $path = $this->path($id);
        if (!is_file($path) || filesize($path) > 2_000_000) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['id'] ?? null) !== $id) {
            return null;
        }
        $compressed = base64_decode(is_string($data['pixels'] ?? null) ? $data['pixels'] : '', true);
        $rgba = is_string($compressed) ? @gzdecode($compressed, MapRecord::PIXEL_BYTES) : false;
        $dimension = is_string($data['dimension'] ?? null) ? DimensionType::tryFrom($data['dimension']) : null;
        $scale = is_int($data['scale'] ?? null) ? MapScale::tryFrom($data['scale']) : null;
        if (!is_string($rgba) || strlen($rgba) !== MapRecord::PIXEL_BYTES || $dimension === null || $scale === null) {
            return null;
        }
        $centerX = $data['centerX'] ?? null;
        $centerZ = $data['centerZ'] ?? null;
        $revision = $data['revision'] ?? null;
        if (!is_int($centerX) || !is_int($centerZ) || !is_int($revision)) {
            return null;
        }
        try {
            $record = new MapRecord(
                $id,
                $dimension,
                $centerX,
                $centerZ,
                $scale,
                ($data['locked'] ?? false) === true,
                ($data['tracking'] ?? false) === true,
                $rgba,
                max(1, $revision),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
        $this->remember($record);
        $this->persistedRevisions[$id] = $record->revision;

        return $record;
    }

    public function isKnownId(int $id): bool
    {
        return $id >= 1 && $id < $this->nextId;
    }

    public function revision(int $id): ?int
    {
        return $this->get($id)?->revision;
    }

    public function replace(MapRecord $record): void
    {
        if (!$this->isKnownId($record->id)) {
            throw new \InvalidArgumentException('Cannot replace an unknown map.');
        }
        $this->remember($record);
        $this->markDirty($record);
        $this->forgetProjections($record->id);
    }

    public function attachTerrainSampler(DimensionType $dimension, MapTerrainSampler $sampler): void
    {
        $this->terrainSamplers[$dimension->value] = $sampler;
    }

    public function invalidateTerrainColumn(DimensionType $dimension, int $x, int $z): void
    {
        ($this->terrainSamplers[$dimension->value] ?? null)?->invalidateColumn($x, $z);
    }

    /** Queues one map for this session without performing disk, rendering, or packet work. */
    /** @param list<MapPixel> $clientPixels */
    public function request(string $sessionId, int $id, array $clientPixels = []): bool
    {
        if ($sessionId === '' || strlen($sessionId) > 128 || !$this->isKnownId($id)) {
            return false;
        }
        $record = $this->get($id);
        if ($record === null) {
            return false;
        }
        if (!$record->tracking || $record->locked) {
            return true;
        }
        if (!isset($this->terrainSubscribers[$id])) {
            $this->terrainOrder[] = $id;
        }
        $this->terrainSubscribers[$id][$sessionId] = true;
        $accepted = 0;
        foreach ($clientPixels as $pixel) {
            if ($pixel->index < 0 || $pixel->index >= MapRecord::WIDTH * MapRecord::HEIGHT
                || $accepted >= 512 || $this->clientHintCount >= MapRecord::WIDTH * MapRecord::HEIGHT) {
                continue;
            }
            if (!isset($this->clientHints[$id][$pixel->index])) {
                ++$this->clientHintCount;
            }
            $this->clientHints[$id][$pixel->index] = $pixel;
            ++$accepted;
        }

        return true;
    }

    public function releaseSession(string $sessionId): void
    {
        foreach ($this->terrainSubscribers as $id => $sessions) {
            unset($sessions[$sessionId]);
            if ($sessions === []) {
                unset($this->terrainSubscribers[$id], $this->terrainCursors[$id]);
            } else {
                $this->terrainSubscribers[$id] = $sessions;
            }
        }
        $this->terrainOrder = array_values(array_filter(
            $this->terrainOrder,
            fn(int $id): bool => isset($this->terrainSubscribers[$id]),
        ));
        $this->terrainOrderCursor = $this->terrainOrder === []
            ? 0
            : $this->terrainOrderCursor % count($this->terrainOrder);
    }

    /** Advances shared terrain maps within both a pixel and wall-clock budget. */
    public function tick(int $maximumPixels = 512, int $maximumNanoseconds = 2_000_000): int
    {
        if ($maximumPixels < 1 || $maximumPixels > 16_384
            || $maximumNanoseconds < 100_000 || $maximumNanoseconds > 10_000_000) {
            throw new \InvalidArgumentException('Map tick budget is invalid.');
        }
        ++$this->tickSequence;
        $started = hrtime(true);
        $processed = $this->applyClientHints(min(128, $maximumPixels), $started, $maximumNanoseconds);
        $visits = 0;
        $maximumVisits = min(8, count($this->terrainOrder));
        while ($processed < $maximumPixels && $this->terrainOrder !== []
            && $visits < $maximumVisits && hrtime(true) - $started < $maximumNanoseconds) {
            if ($this->terrainOrderCursor >= count($this->terrainOrder)) {
                $this->terrainOrderCursor = 0;
            }
            $id = $this->terrainOrder[$this->terrainOrderCursor++];
            if (!isset($this->terrainSubscribers[$id])) {
                continue;
            }
            $record = $this->get($id);
            if ($record === null || !$record->tracking || $record->locked) {
                unset($this->terrainSubscribers[$id], $this->terrainCursors[$id]);
                ++$visits;
                continue;
            }
            $sampler = $this->terrainSamplers[$record->dimension->value] ?? null;
            if ($sampler === null) {
                break;
            }
            $slice = min(self::TERRAIN_PIXELS_PER_SLICE, $maximumPixels - $processed);
            $processed += $this->renderTerrainSlice($record, $sampler, $slice, $started, $maximumNanoseconds);
            ++$visits;
        }
        $this->terrainOrder = array_values(array_filter(
            $this->terrainOrder,
            fn(int $id): bool => isset($this->terrainSubscribers[$id]),
        ));
        $this->flushDirty(1, 750_000);

        return $processed;
    }

    private function applyClientHints(int $maximumPixels, int $started, int $maximumNanoseconds): int
    {
        $processed = 0;
        foreach (array_keys($this->clientHints) as $id) {
            if ($processed >= $maximumPixels || hrtime(true) - $started >= $maximumNanoseconds) {
                break;
            }
            $record = $this->get($id);
            if ($record === null || !$record->tracking || $record->locked || !isset($this->terrainSubscribers[$id])) {
                $this->clientHintCount -= count($this->clientHints[$id]);
                unset($this->clientHints[$id]);
                continue;
            }
            $rgba = $record->rgba;
            $minimumX = MapRecord::WIDTH;
            $minimumY = MapRecord::HEIGHT;
            $maximumX = -1;
            $maximumY = -1;
            foreach ($this->clientHints[$id] as $index => $pixel) {
                if ($processed >= $maximumPixels || hrtime(true) - $started >= $maximumNanoseconds) {
                    break;
                }
                unset($this->clientHints[$id][$index]);
                --$this->clientHintCount;
                ++$processed;
                $offset = $index * 4;
                if (ord($rgba[$offset + 3]) !== 0) {
                    continue;
                }
                $color = $pixel->color & 0xffffffff;
                $rgba[$offset] = chr($color & 0xff);
                $rgba[$offset + 1] = chr(($color >> 8) & 0xff);
                $rgba[$offset + 2] = chr(($color >> 16) & 0xff);
                $rgba[$offset + 3] = chr(($color >> 24) & 0xff);
                $x = $index % MapRecord::WIDTH;
                $y = intdiv($index, MapRecord::WIDTH);
                $minimumX = min($minimumX, $x);
                $minimumY = min($minimumY, $y);
                $maximumX = max($maximumX, $x);
                $maximumY = max($maximumY, $y);
            }
            if ($this->clientHints[$id] === []) {
                unset($this->clientHints[$id]);
            }
            if ($maximumX >= 0) {
                $this->replacePixels($record, $rgba, $minimumX, $minimumY, $maximumX, $maximumY);
            }
        }

        return $processed;
    }

    public function projection(int $id, int $afterRevision = 0): ?PreparedMapProjection
    {
        $record = $this->get($id);
        if ($record === null || $afterRevision === $record->revision) {
            return null;
        }
        $x = 0;
        $y = 0;
        $width = MapRecord::WIDTH;
        $height = MapRecord::HEIGHT;
        $delta = $this->latestDeltas[$id] ?? null;
        if ($delta !== null
            && $delta->fromRevision === $afterRevision
            && $delta->toRevision === $record->revision) {
            $x = $delta->x;
            $y = $delta->y;
            $width = $delta->width;
            $height = $delta->height;
        }
        $key = implode(':', [$id, $record->revision, $x, $y, $width, $height]);
        if (isset($this->projections[$key])) {
            $this->projectionUse[$key] = ++$this->usageSequence;

            return $this->projections[$key];
        }
        $packet = $this->packetForRegion($record, $x, $y, $width, $height);
        $frame = new PacketFrame(
            new PacketHeader(BedrockPacketCodec::packetId($packet)),
            BedrockPacketCodec::encode($packet),
        );
        $compressed = BedrockBatchCodec::encode(
            new BedrockBatch([$frame], CompressionMode::NegotiatedZlib, NetworkCompressionPolicy::THRESHOLD_BYTES),
            new BatchLimits(),
        );
        $projection = new PreparedMapProjection($record->revision, $packet, $frame, $compressed);
        $this->projections[$key] = $projection;
        $this->projectionUse[$key] = ++$this->usageSequence;
        $this->projectionBytes += $projection->retainedBytes();
        $this->trimProjections();

        return $projection;
    }

    public function packet(int $id): ?ClientboundMapItemDataPacket
    {
        return $this->projection($id)?->packet;
    }

    public function flush(): void
    {
        while ($this->dirty !== []) {
            $this->flushDirty(max(1, count($this->dirty)), PHP_INT_MAX, true);
        }
        if ($this->indexDirty) {
            $this->writeIndex();
            $this->indexDirty = false;
        }
    }

    private function renderTerrainSlice(
        MapRecord $record,
        MapTerrainSampler $sampler,
        int $maximumPixels,
        int $started,
        int $maximumNanoseconds,
    ): int {
        $rgba = $record->rgba;
        $cursor = $this->terrainCursors[$record->id] ?? 0;
        $minimumX = MapRecord::WIDTH;
        $minimumY = MapRecord::HEIGHT;
        $maximumX = -1;
        $maximumY = -1;
        $processed = 0;
        $scale = $record->scale->value;
        $startX = $record->centerX - 64 * $scale;
        $startZ = $record->centerZ - 64 * $scale;
        while ($processed < $maximumPixels && hrtime(true) - $started < $maximumNanoseconds) {
            $pixelX = $cursor % MapRecord::WIDTH;
            $pixelY = intdiv($cursor, MapRecord::WIDTH);
            $color = $sampler->colorAt($startX + $pixelX * $scale, $startZ + $pixelY * $scale, $scale);
            $offset = $cursor * 4;
            if (substr($rgba, $offset, 4) !== $color) {
                $rgba[$offset] = $color[0];
                $rgba[$offset + 1] = $color[1];
                $rgba[$offset + 2] = $color[2];
                $rgba[$offset + 3] = $color[3];
                $minimumX = min($minimumX, $pixelX);
                $minimumY = min($minimumY, $pixelY);
                $maximumX = max($maximumX, $pixelX);
                $maximumY = max($maximumY, $pixelY);
            }
            $cursor = ($cursor + 1) % (MapRecord::WIDTH * MapRecord::HEIGHT);
            ++$processed;
        }
        $this->terrainCursors[$record->id] = $cursor;
        if ($maximumX >= 0) {
            $this->replacePixels($record, $rgba, $minimumX, $minimumY, $maximumX, $maximumY);
        }

        return $processed;
    }

    private function replacePixels(
        MapRecord $record,
        string $rgba,
        int $minimumX,
        int $minimumY,
        int $maximumX,
        int $maximumY,
    ): void {
        $next = $record->withPixels($rgba);
        if ($next === $record) {
            return;
        }
        $this->remember($next);
        $this->markDirty($next);
        $this->latestDeltas[$record->id] = new MapPixelDelta(
            $record->revision,
            $next->revision,
            $minimumX,
            $minimumY,
            $maximumX - $minimumX + 1,
            $maximumY - $minimumY + 1,
        );
        $this->forgetProjections($record->id);
    }

    private function packetForRegion(
        MapRecord $record,
        int $x,
        int $y,
        int $width,
        int $height,
    ): ClientboundMapItemDataPacket {
        $packedColors = '';
        for ($row = 0; $row < $height; ++$row) {
            $offset = (($y + $row) * MapRecord::WIDTH + $x) * 4;
            $packedColors .= substr($record->rgba, $offset, $width * 4);
        }

        return new ClientboundMapItemDataPacket(
            $record->id,
            match ($record->dimension) {
                DimensionType::OVERWORLD => 0,
                DimensionType::NETHER => 1,
                DimensionType::END => 2,
            },
            $record->locked,
            new BlockPosition($record->centerX, 0, $record->centerZ),
            [$record->id],
            $record->scale->protocolValue(),
            width: $width,
            height: $height,
            xOffset: $x,
            yOffset: $y,
            packedColors: new PackedMapColors($packedColors),
        );
    }

    private function create(
        DimensionType $dimension,
        int $centerX,
        int $centerZ,
        MapScale $scale,
        bool $locked,
        bool $tracking,
        string $rgba,
    ): MapRecord {
        if ($this->nextId > self::MAXIMUM_MAPS) {
            throw new \OverflowException('The world map limit has been reached.');
        }
        $id = $this->nextId++;
        $record = new MapRecord($id, $dimension, $centerX, $centerZ, $scale, $locked, $tracking, $rgba);
        $this->remember($record);
        $this->markDirty($record);
        $this->indexDirty = true;

        return $record;
    }

    private function markDirty(MapRecord $record): void
    {
        $this->dirtySinceTicks[$record->id] ??= $this->tickSequence;
        $this->dirty[$record->id] = $record;
    }

    private function flushDirty(int $maximumRecords, int $maximumNanoseconds, bool $force = false): int
    {
        $started = hrtime(true);
        $written = 0;
        foreach ($this->dirty as $id => $record) {
            if ($written >= $maximumRecords || hrtime(true) - $started >= $maximumNanoseconds) {
                break;
            }
            $persistedRevision = $this->persistedRevisions[$id] ?? null;
            $dirtySinceTick = $this->dirtySinceTicks[$id] ?? $this->tickSequence;
            if (!$force && $persistedRevision !== null
                && $record->revision - $persistedRevision < self::PERSISTENCE_REVISION_INTERVAL
                && $this->tickSequence - $dirtySinceTick < self::PERSISTENCE_TICK_INTERVAL) {
                continue;
            }
            $this->write($record);
            if (($this->dirty[$id] ?? null)?->revision === $record->revision) {
                unset($this->dirty[$id], $this->dirtySinceTicks[$id]);
            }
            ++$written;
        }
        if ($this->indexDirty && hrtime(true) - $started < $maximumNanoseconds) {
            $this->writeIndex();
            $this->indexDirty = false;
        }

        return $written;
    }

    private function write(MapRecord $record): void
    {
        // Map pixels are already high-entropy image data; the fastest level keeps
        // persistence work bounded without materially increasing world storage.
        $compressed = gzencode($record->rgba, 1);
        if (!is_string($compressed)) {
            throw new RuntimeException('Could not compress map pixels.');
        }
        $json = json_encode([
            'schema' => 1,
            'id' => $record->id,
            'dimension' => $record->dimension->value,
            'centerX' => $record->centerX,
            'centerZ' => $record->centerZ,
            'scale' => $record->scale->value,
            'locked' => $record->locked,
            'tracking' => $record->tracking,
            'revision' => $record->revision,
            'pixels' => base64_encode($compressed),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->atomicWrite($this->path($record->id), $json . "\n");
        $this->persistedRevisions[$record->id] = $record->revision;
    }

    private function writeIndex(): void
    {
        $json = json_encode(['schema' => 1, 'nextId' => $this->nextId], JSON_THROW_ON_ERROR);
        $this->atomicWrite($this->directory . DIRECTORY_SEPARATOR . 'index.json', $json . "\n");
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not atomically persist map data.');
        }
    }

    private function path(int $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function remember(MapRecord $record): void
    {
        if (!isset($this->cache[$record->id])) {
            $this->cacheBytes += MapRecord::PIXEL_BYTES;
        }
        $this->cache[$record->id] = $record;
        $this->cacheUse[$record->id] = ++$this->usageSequence;
        while ($this->cacheBytes > self::MAXIMUM_CACHE_BYTES) {
            $oldestId = null;
            $oldestUse = PHP_INT_MAX;
            foreach ($this->cacheUse as $id => $use) {
                if (!isset($this->dirty[$id]) && !isset($this->terrainSubscribers[$id]) && $use < $oldestUse) {
                    $oldestId = $id;
                    $oldestUse = $use;
                }
            }
            if ($oldestId === null) {
                break;
            }
            unset($this->cache[$oldestId], $this->cacheUse[$oldestId]);
            $this->cacheBytes -= MapRecord::PIXEL_BYTES;
        }
    }

    private function forgetProjections(int $mapId): void
    {
        $prefix = $mapId . ':';
        foreach (array_keys($this->projections) as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $this->projectionBytes -= $this->projections[$key]->retainedBytes();
            unset($this->projections[$key], $this->projectionUse[$key]);
        }
    }

    private function trimProjections(): void
    {
        while (count($this->projections) > self::MAXIMUM_PROJECTIONS
            || $this->projectionBytes > self::MAXIMUM_PROJECTION_BYTES) {
            $oldestKey = null;
            $oldestUse = PHP_INT_MAX;
            foreach ($this->projectionUse as $key => $use) {
                if ($use < $oldestUse) {
                    $oldestKey = $key;
                    $oldestUse = $use;
                }
            }
            if ($oldestKey === null) {
                break;
            }
            $this->projectionBytes -= $this->projections[$oldestKey]->retainedBytes();
            unset($this->projections[$oldestKey], $this->projectionUse[$oldestKey]);
        }
    }
}
