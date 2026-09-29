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

namespace Bedriox\Server\Runtime;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Protocol\Packet\ChunkColumnData;
use Bedriox\Protocol\Packet\ChunkSectionData;
use Bedriox\Protocol\Packet\ChunkSerializer;
use Bedriox\Protocol\Packet\LevelChunkPacket;
use Bedriox\Protocol\Packet\PackedPalettedStorage;
use Bedriox\Server\World\BiomeRuntimeIdMap;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;
use InvalidArgumentException;

/** Translates Bedriox-owned world state into the current Bedrock full-column wire model. */
final class BedrockChunkPacketSerializer
{
    /** @var \WeakMap<Chunk, LevelChunkPacket> */
    private \WeakMap $cache;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    private readonly BiomeRuntimeIdMap $biomes;

    private readonly PersistentBlockEntityCodec $blockEntities;

    public function __construct(
        private readonly BlockNetworkTranslator $blocks,
        private readonly int $maximumCachedPackets = 256,
        ?BiomeRuntimeIdMap $biomes = null,
        ?PersistentBlockEntityCodec $blockEntities = null,
    ) {
        if ($maximumCachedPackets < 1 || $maximumCachedPackets > 4_096) {
            throw new InvalidArgumentException('Chunk packet cache limit must be between 1 and 4096.');
        }
        $this->biomes = $biomes ?? BiomeRuntimeIdMap::bundled();
        $this->blockEntities = $blockEntities ?? new PersistentBlockEntityCodec();
        $this->cache = new \WeakMap();
    }

    public function serialize(Chunk $chunk): LevelChunkPacket
    {
        $cached = $this->cache[$chunk] ?? null;
        if ($cached instanceof LevelChunkPacket) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;
        $highestSectionY = Chunk::MIN_SECTION_Y;
        foreach ($chunk->populatedSections() as $section) {
            $highestSectionY = max($highestSectionY, $section->sectionY);
        }

        $airRuntimeId = $this->blocks->toNetwork($chunk->airState());
        $sections = [];
        for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= $highestSectionY; ++$sectionY) {
            $section = $chunk->section($sectionY);
            if ($section === null) {
                $sections[] = ChunkSectionData::allAir($sectionY, $airRuntimeId);
                continue;
            }

            $storage = $section->blockStorageLayer(0);
            $palette = [];
            foreach ($storage->palette() as $state) {
                $palette[] = $this->blocks->toNetwork($state);
            }
            $sections[] = new ChunkSectionData($sectionY, [new PackedPalettedStorage(
                $palette,
                $storage->networkBitsPerEntry(),
                $storage->networkWordArray(),
                ChunkSectionData::CELL_COUNT,
            )]);
        }

        $biomes = [];
        /** @var \WeakMap<\Bedriox\Server\World\BiomeStorage, PackedPalettedStorage> $projectedBiomeStorages */
        $projectedBiomeStorages = new \WeakMap();
        for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= Chunk::MAX_SECTION_Y; ++$sectionY) {
            $storage = $chunk->biomeStorage($sectionY);
            $projected = $projectedBiomeStorages[$storage] ?? null;
            if (!$projected instanceof PackedPalettedStorage) {
                $palette = [];
                foreach ($storage->palette() as $biome) {
                    $palette[] = $this->biomes->id($biome);
                }
                $projected = new PackedPalettedStorage(
                    $palette,
                    $storage->networkBitsPerEntry(),
                    $storage->networkWordArray(),
                    ChunkColumnData::BIOME_CELL_COUNT,
                );
                $projectedBiomeStorages[$storage] = $projected;
            }
            $biomes[] = $projected;
        }

        $packet = ChunkSerializer::fullColumn(new ChunkColumnData(
            $chunk->position->x,
            $chunk->position->z,
            0,
            Chunk::MIN_SECTION_Y,
            Chunk::MAX_SECTION_Y,
            $sections,
            $biomes,
            $this->blockEntities->encodeNetwork($chunk->blockEntityCollection()),
        ));
        if (count($this->cache) >= $this->maximumCachedPackets) {
            $this->cache = new \WeakMap();
        }
        $this->cache[$chunk] = $packet;

        return $packet;
    }

    /** @return array{entries: int, hits: int, misses: int} */
    public function cacheMetrics(): array
    {
        return ['entries' => count($this->cache), 'hits' => $this->cacheHits, 'misses' => $this->cacheMisses];
    }

    /** Translates one authoritative world cell without exposing internal state IDs to the play channel. */
    public function networkRuntimeIdAt(Chunk $chunk, int $worldX, int $y, int $worldZ): int
    {
        $chunkX = (int) floor($worldX / 16.0);
        $chunkZ = (int) floor($worldZ / 16.0);
        if ($chunk->position->x !== $chunkX || $chunk->position->z !== $chunkZ) {
            throw new InvalidArgumentException('Block coordinate does not belong to the supplied chunk.');
        }
        $localX = (($worldX % 16) + 16) % 16;
        $localZ = (($worldZ % 16) + 16) % 16;

        return $this->blocks->toNetwork($chunk->blockStateAt($localX, $y, $localZ));
    }

    public function networkRuntimeId(\Bedriox\Server\World\Block\InternalBlockStateId $state): int
    {
        return $this->blocks->toNetwork($state);
    }

    /** @param array<string, int|string> $properties */
    public function networkRuntimeIdForCanonicalState(string $identifier, array $properties): int
    {
        return $this->blocks->toNetwork($this->blocks->internalRegistry()->internalId(
            CanonicalBlockState::from($identifier, $properties),
        ));
    }
}
