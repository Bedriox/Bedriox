<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferException;
use Bedriox\Server\World\Biome;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkFinalizationState;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\SubChunk;
use Bedriox\Server\World\SubChunkBlockStorage;
use PHPUnit\Framework\TestCase;

final class ChunkTransferCodecTest extends TestCase
{
    public function testCanonicalRoundTripDoesNotDependOnProcessLocalIds(): void
    {
        $air = CanonicalBlockState::from('minecraft:air');
        $stone = CanonicalBlockState::from('minecraft:stone', ['stone_type' => 'stone']);
        $sourceStates = new BlockStateRegistry([$air, $stone]);
        $targetStates = new BlockStateRegistry([CanonicalBlockState::from('aaa:shifts_ids'), $air, $stone]);
        $indices = str_repeat("\x00", SubChunkBlockStorage::BLOCK_COUNT);
        $indices[17] = "\x01";
        $chunk = new Chunk(
            new ChunkPosition(-12, 34),
            $sourceStates->internalId($air),
            [SubChunk::fromBlockStorageLayers(-4, [SubChunkBlockStorage::fromPaletteIndices([
                $sourceStates->internalId($air),
                $sourceStates->internalId($stone),
            ], $indices)])],
            Biome::forest(),
            9,
            7,
            Chunk::DIRTY_BLOCKS,
            ChunkFinalizationState::NeedsPopulation,
        );

        $codec = new ChunkTransferCodec();
        $encoded = $codec->encode($chunk, $sourceStates);
        $decoded = $codec->decode($encoded, $targetStates);

        self::assertSame($encoded, $codec->encode($chunk, $sourceStates));
        self::assertStringNotContainsString('O:', $encoded);
        self::assertSame([-12, 34, 9, 7, Chunk::DIRTY_BLOCKS], [
            $decoded->position->x,
            $decoded->position->z,
            $decoded->revision,
            $decoded->persistedRevision,
            $decoded->dirtyFlags,
        ]);
        self::assertSame(ChunkFinalizationState::NeedsPopulation, $decoded->finalizationState);
        self::assertSame('minecraft:forest', $decoded->biome()->identifier);
        self::assertSame('minecraft:stone', $targetStates->state($decoded->blockStateAt(1, Chunk::MIN_Y, 1))->identifier());
        self::assertNotSame(
            $chunk->blockStateAt(1, Chunk::MIN_Y, 1)->value,
            $decoded->blockStateAt(1, Chunk::MIN_Y, 1)->value,
            'The fixture must prove canonical identity survives different process-local IDs.',
        );
    }

    public function testCorruptTruncatedTrailingAndOversizedTransfersFailClosed(): void
    {
        $air = CanonicalBlockState::from('minecraft:air');
        $states = new BlockStateRegistry([$air]);
        $codec = new ChunkTransferCodec();
        $encoded = $codec->encode(new Chunk(new ChunkPosition(0, 0), $states->internalId($air), []), $states);
        $corrupt = $encoded;
        $corrupt[20] = chr(ord($corrupt[20]) ^ 1);
        $oversized = substr_replace($encoded, pack('N', ChunkTransferCodec::MAXIMUM_ENCODED_BYTES), 6, 4);

        foreach ([$corrupt, substr($encoded, 0, -1), $encoded . "\x00", $oversized] as $invalid) {
            try {
                $codec->decode($invalid, $states);
                self::fail('A malformed chunk transfer was accepted.');
            } catch (ChunkTransferException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
