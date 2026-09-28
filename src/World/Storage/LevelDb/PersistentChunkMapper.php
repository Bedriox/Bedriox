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

namespace Bedriox\Server\World\Storage\LevelDb;

use Bedriox\Data\KnownPersistentBlockState;
use Bedriox\Data\OpaquePersistentBlockState;
use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\World\BiomeStorage;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkFinalizationState;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\SubChunk;
use Bedriox\Server\World\SubChunkBlockStorage;
use InvalidArgumentException;

/** Translates canonical persistence values without exposing process-local IDs on disk. */
final readonly class PersistentChunkMapper
{
    public function __construct(
        private BlockStateRegistry $blockStates,
        private PersistentBlockStateRegistry $persistentBlockStates,
        private PersistentBiomeRegistry $biomes = new PersistentBiomeRegistry(),
    ) {}

    public function storedSubChunk(SubChunk $section): StoredSubChunk
    {
        $storages = [];
        foreach ($section->blockStorageLayers() as $storage) {
            $palette = [];
            foreach ($storage->palette() as $internalId) {
                try {
                    $palette[] = $this->persistentBlockStates->knownState($this->blockStates->state($internalId));
                } catch (InvalidArgumentException $error) {
                    throw new LevelDbStorageException('An internal block state has no admitted persistent representation.', previous: $error);
                }
            }
            $indices = [];
            $encodedIndices = $storage->paletteIndices();
            for ($offset = 0; $offset < SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
                $indices[] = ord($encodedIndices[$offset]);
            }
            $storages[] = new PersistentBlockStorage($palette, $indices);
        }

        return new StoredSubChunk($section->sectionY, $storages);
    }

    public function runtimeSubChunk(StoredSubChunk $stored): SubChunk
    {
        $layers = [];
        foreach ($stored->storages() as $storage) {
            $palette = [];
            foreach ($storage->palette() as $persistentState) {
                if ($persistentState instanceof OpaquePersistentBlockState) {
                    throw new LevelDbStorageException('A valid but unadmitted persistent block state cannot be projected into authoritative world state.');
                }
                if (!$persistentState instanceof KnownPersistentBlockState) {
                    throw new LevelDbStorageException('Persistent block palette contains an unsupported state implementation.');
                }
                try {
                    $palette[] = $this->blockStates->internalId($persistentState->canonicalState());
                } catch (InvalidArgumentException $error) {
                    throw new LevelDbStorageException('A persistent block state is absent from the internal registry.', previous: $error);
                }
            }
            if (count($palette) > 256) {
                throw new LevelDbStorageException('Persistent block palette exceeds the authoritative in-memory palette limit.');
            }
            $indices = '';
            foreach ($storage->indices() as $index) {
                if ($index < 0 || $index > 255) {
                    throw new LevelDbStorageException('Persistent block palette index exceeds the authoritative in-memory range.');
                }
                $indices .= chr($index);
            }
            $layers[] = SubChunkBlockStorage::fromPaletteIndices($palette, $indices);
        }

        return SubChunk::fromBlockStorageLayers($stored->sectionY, $layers);
    }

    public function data3d(Chunk $chunk): Data3dRecord
    {
        $persistentBiomes = [];
        foreach ($chunk->biomeStorages() as $storage) {
            $palette = [];
            foreach ($storage->palette() as $biome) {
                $palette[] = $this->biomes->id($biome);
            }
            $indices = [];
            $encodedIndices = $storage->paletteIndices();
            for ($offset = 0; $offset < BiomeStorage::BIOME_COUNT; ++$offset) {
                $indices[] = ord($encodedIndices[$offset]);
            }
            $persistentBiomes[] = new PersistentBiomeStorage($palette, $indices);
        }

        // PMMP writes a bounded zeroed placeholder because Bedrock recalculates this cache.
        return new Data3dRecord(str_repeat("\0", Data3dRecord::HEIGHTMAP_BYTES), $persistentBiomes);
    }

    /** @return array<int, BiomeStorage> */
    public function runtimeBiomes(Data3dRecord $record): array
    {
        $result = [];
        foreach ($record->biomes() as $offset => $storage) {
            $palette = [];
            foreach ($storage->palette() as $id) {
                $palette[] = $this->biomes->biome($id);
            }
            if (count($palette) > 256) {
                throw new LevelDbStorageException('Persistent biome palette exceeds the authoritative in-memory palette limit.');
            }
            $indices = '';
            foreach ($storage->indices() as $index) {
                if ($index < 0 || $index > 255) {
                    throw new LevelDbStorageException('Persistent biome palette index exceeds the authoritative in-memory range.');
                }
                $indices .= chr($index);
            }
            $result[Chunk::MIN_SECTION_Y + $offset] = BiomeStorage::fromPaletteIndices($palette, $indices);
        }

        return $result;
    }

    /**
     * @param list<SubChunk>             $sections
     * @param array<int, BiomeStorage>   $biomes
     */
    public function chunk(
        ChunkPosition $position,
        array $sections,
        array $biomes,
        ChunkFinalizationState $finalization,
        ?BlockEntityCollection $blockEntities = null,
    ): Chunk {
        try {
            $air = $this->blockStates->internalId(\Bedriox\Server\World\Block\VanillaBlockStates::air());
        } catch (InvalidArgumentException $error) {
            throw new LevelDbStorageException('The internal registry does not contain canonical air.', previous: $error);
        }
        $defaultBiomeStorage = $biomes[Chunk::MIN_SECTION_Y] ?? null;
        $defaultBiome = $defaultBiomeStorage?->biomeAt(0, 0, 0)
            ?? throw new LevelDbStorageException('Decoded Data3D has no minimum-height biome storage.');

        return new Chunk(
            $position,
            $air,
            $sections,
            $defaultBiome,
            finalizationState: $finalization,
            biomeStorages: $biomes,
            blockEntities: $blockEntities,
        );
    }
}
