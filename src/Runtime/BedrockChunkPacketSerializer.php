<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\ChunkColumnData;
use Bedriox\Protocol\Packet\ChunkSectionData;
use Bedriox\Protocol\Packet\ChunkSerializer;
use Bedriox\Protocol\Packet\LevelChunkPacket;
use Bedriox\Protocol\Packet\PalettedStorage;
use Bedriox\Server\World\BiomeRuntimeIdMap;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Chunk;
use InvalidArgumentException;

/** Translates Bedriox-owned world state into the current Bedrock full-column wire model. */
final readonly class BedrockChunkPacketSerializer
{
    public function __construct(
        private BlockNetworkTranslator $blocks,
        private int $plainsBiomeRuntimeId,
    ) {
        if ($plainsBiomeRuntimeId < 0 || $plainsBiomeRuntimeId > 65_535) {
            throw new InvalidArgumentException('Plains biome runtime ID is outside its supported range.');
        }
    }

    public function serialize(Chunk $chunk): LevelChunkPacket
    {
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

            // Bedrock's subchunk cell order is Y-fastest: ((x * 16) + z) * 16 + y.
            $runtimeIds = [];
            for ($x = 0; $x < 16; ++$x) {
                for ($z = 0; $z < 16; ++$z) {
                    for ($y = 0; $y < 16; ++$y) {
                        $runtimeIds[] = $this->blocks->toNetwork($section->blockStateAt($x, $y, $z));
                    }
                }
            }
            $sections[] = ChunkSectionData::fromRuntimeIds($sectionY, $runtimeIds);
        }

        $biomes = [];
        for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= Chunk::MAX_SECTION_Y; ++$sectionY) {
            $storage = $chunk->biomeStorage($sectionY);
            $runtimeIds = [];
            for ($x = 0; $x < 16; ++$x) {
                for ($z = 0; $z < 16; ++$z) {
                    for ($y = 0; $y < 16; ++$y) {
                        $biome = $storage->biomeAt($x, $y, $z);
                        $runtimeIds[] = $biome->identifier === 'minecraft:plains'
                            ? $this->plainsBiomeRuntimeId
                            : BiomeRuntimeIdMap::id($biome);
                    }
                }
            }
            $uniqueRuntimeIds = array_values(array_unique($runtimeIds, SORT_REGULAR));
            $biomes[] = count($uniqueRuntimeIds) === 1
                ? PalettedStorage::singleton($uniqueRuntimeIds[0], ChunkColumnData::BIOME_CELL_COUNT, 2)
                : PalettedStorage::fromValues($runtimeIds, ChunkColumnData::BIOME_CELL_COUNT);
        }

        return ChunkSerializer::fullColumn(new ChunkColumnData(
            $chunk->position->x,
            $chunk->position->z,
            0,
            Chunk::MIN_SECTION_Y,
            Chunk::MAX_SECTION_Y,
            $sections,
            $biomes,
        ));
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
}
