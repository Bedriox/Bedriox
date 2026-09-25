<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\World\Biome;
use Bedriox\Server\World\BiomeStorage;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkFinalizationState;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\SubChunk;
use Bedriox\Server\World\SubChunkBlockStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChunkPersistenceModelTest extends TestCase
{
    public function testMultipleBlockStorageLayersRemainIndependent(): void
    {
        $air = new InternalBlockStateId(0);
        $solid = new InternalBlockStateId(1);
        $water = new InternalBlockStateId(2);
        $section = SubChunk::fromBlockStorageLayers(0, [
            SubChunkBlockStorage::uniform($solid),
            SubChunkBlockStorage::uniform($water),
        ]);

        self::assertSame(1, $section->blockStateAt(3, 4, 5)->value);
        self::assertSame(2, $section->blockStorageLayer(1)->blockStateAt(3, 4, 5)->value);

        $changed = $section->withBlockStateInLayer(0, 3, 4, 5, $air);
        self::assertSame(0, $changed->blockStateAt(3, 4, 5)->value);
        self::assertSame(2, $changed->blockStorageLayer(1)->blockStateAt(3, 4, 5)->value);
        self::assertSame(1, $section->blockStateAt(3, 4, 5)->value);
    }

    public function testBiomeStorageIsSectionLocalAndUsesCanonicalIdentities(): void
    {
        $plains = Biome::plains();
        $desert = new Biome('minecraft:desert');
        $air = new InternalBlockStateId(0);
        $chunk = new Chunk(new ChunkPosition(-1, 2), $air, [], $plains);

        self::assertCount(Chunk::SECTION_COUNT, $chunk->biomeStorages());
        self::assertSame('minecraft:plains', $chunk->biomeAt(2, 31, 5)->identifier);

        $changed = $chunk->withBiome(2, 31, 5, $desert);
        self::assertSame('minecraft:desert', $changed->biomeAt(2, 31, 5)->identifier);
        self::assertSame('minecraft:plains', $changed->biomeAt(3, 31, 5)->identifier);
        self::assertSame('minecraft:plains', $changed->biomeAt(2, 32, 5)->identifier);
        self::assertSame([$plains, $desert], $changed->biomeStorage(1)->palette());
    }

    public function testRevisionDirtyFlagsAndSaveAcknowledgementAreImmutable(): void
    {
        $air = new InternalBlockStateId(0);
        $stone = new InternalBlockStateId(1);
        $chunk = new Chunk(new ChunkPosition(0, 0), $air, []);
        self::assertFalse($chunk->isDirty());
        self::assertSame(0, $chunk->revision);

        $blocksChanged = $chunk->withBlockState(1, 0, 1, $stone);
        self::assertSame(1, $blocksChanged->revision);
        self::assertTrue($blocksChanged->hasDirtyFlag(Chunk::DIRTY_BLOCKS));
        self::assertFalse($chunk->isDirty());

        $finalized = $blocksChanged->withFinalizationState(ChunkFinalizationState::NeedsPopulation);
        self::assertSame(2, $finalized->revision);
        self::assertTrue($finalized->hasDirtyFlag(Chunk::DIRTY_BLOCKS));
        self::assertTrue($finalized->hasDirtyFlag(Chunk::DIRTY_FINALIZATION));

        $partiallyAcknowledged = $finalized->withPersistedRevision(1);
        self::assertTrue($partiallyAcknowledged->isDirty());
        self::assertSame(1, $partiallyAcknowledged->persistedRevision);

        $saved = $finalized->withPersistedRevision(2);
        self::assertFalse($saved->isDirty());
        self::assertSame(2, $saved->persistedRevision);
        self::assertSame(ChunkFinalizationState::NeedsPopulation, $saved->finalizationState);
    }

    public function testPersistenceModelRejectsInvalidBoundsAndPaletteIndices(): void
    {
        $air = new InternalBlockStateId(0);

        $invalid = [
            static fn(): Chunk => new Chunk(new ChunkPosition(0, 0), $air, [], revision: -1),
            static fn(): Chunk => new Chunk(new ChunkPosition(0, 0), $air, [], revision: 1, persistedRevision: 2),
            static fn(): Chunk => new Chunk(new ChunkPosition(0, 0), $air, [], dirtyFlags: 1 << 4),
            static fn(): BiomeStorage => BiomeStorage::fromPaletteIndices([Biome::plains()], str_repeat("\x01", 4096)),
            static fn(): SubChunkBlockStorage => SubChunkBlockStorage::fromPaletteIndices([$air], str_repeat("\x01", 4096)),
        ];
        foreach ($invalid as $factory) {
            try {
                $factory();
                self::fail('Invalid persistence-model data was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
