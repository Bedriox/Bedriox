<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\ChunkColumnData;
use Bedriox\Protocol\Packet\ChunkSectionData;
use Bedriox\Protocol\Packet\ChunkSerializer;
use Bedriox\Protocol\Packet\LevelChunkPacket;
use Bedriox\Protocol\Packet\PalettedStorage;
use Bedriox\Server\Runtime\BedrockChunkPacketSerializer;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\FlatWorldGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BedrockChunkPacketSerializerTest extends TestCase
{
    public function testInternalFlatChunkMatchesQualifiedFullColumnWireShape(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $serializer = new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $network),
        );

        $packet = $serializer->serialize(
            (new FlatWorldGenerator($palette))->generate(new ChunkPosition(-7, 11)),
        );

        self::assertEquals(
            LevelChunkPacket::fixedFlat(
                -7,
                11,
                $palette->toNetworkRuntimeIds(new BlockNetworkTranslator($internal, $network)),
                $data->plainsBiomeRuntimeId(),
            ),
            $packet,
        );
    }

    public function testAuthoritativeCellTranslationHandlesNegativeWorldCoordinates(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $translator = new BlockNetworkTranslator($internal, $network);
        $serializer = new BedrockChunkPacketSerializer($translator);
        $chunk = (new FlatWorldGenerator($palette))->generate(new ChunkPosition(-1, -1));

        self::assertSame(
            $translator->toNetwork($palette->grassBlock),
            $serializer->networkRuntimeIdAt($chunk, -1, 63, -1),
        );
        self::assertSame(
            $translator->toNetwork($palette->air),
            $serializer->networkRuntimeIdAt($chunk, -16, 64, -16),
        );

        $this->expectException(InvalidArgumentException::class);
        $serializer->networkRuntimeIdAt($chunk, 0, 63, -1);
    }

    public function testPreparedPacketCacheIsBoundToTheExactImmutableChunkRevision(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $serializer = new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $network),
            2,
        );
        $chunk = (new FlatWorldGenerator($palette))->generate(new ChunkPosition(0, 0));

        $first = $serializer->serialize($chunk);
        self::assertSame($first, $serializer->serialize($chunk));
        self::assertSame(['entries' => 1, 'hits' => 1, 'misses' => 1], $serializer->cacheMetrics());

        $changed = $chunk->withBlockState(0, 63, 0, $palette->air);
        $changedPacket = $serializer->serialize($changed);
        self::assertNotSame($first, $changedPacket);
        self::assertNotSame($first->data, $changedPacket->data);
        self::assertSame(['entries' => 2, 'hits' => 1, 'misses' => 2], $serializer->cacheMetrics());
    }

    public function testPackedProjectionPreservesAsymmetricCellCoordinates(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $translator = new BlockNetworkTranslator($internal, $network);
        $chunk = (new FlatWorldGenerator($palette))->generate(new ChunkPosition(0, 0))
            ->withBlockState(3, 64, 7, $palette->dirt)
            ->withBlockState(11, 69, 2, $palette->bedrock);
        $serializer = new BedrockChunkPacketSerializer($translator);

        $sections = [];
        for ($sectionY = -4; $sectionY <= 4; ++$sectionY) {
            $values = [];
            for ($x = 0; $x < 16; ++$x) {
                for ($z = 0; $z < 16; ++$z) {
                    for ($y = 0; $y < 16; ++$y) {
                        $values[] = $translator->toNetwork($chunk->blockStateAt($x, ($sectionY * 16) + $y, $z));
                    }
                }
            }
            $sections[] = ChunkSectionData::fromRuntimeIds($sectionY, $values);
        }
        $biome = PalettedStorage::singleton($data->plainsBiomeRuntimeId(), 4096, 2);
        $expanded = ChunkSerializer::fullColumn(new ChunkColumnData(
            0,
            0,
            0,
            -4,
            19,
            $sections,
            array_fill(0, 24, $biome),
        ));

        self::assertSame($expanded->data, $serializer->serialize($chunk)->data);
    }

    public function testDefaultGeneratorMixedBiomeChunkHasUniqueWirePalette(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $chunk = (new DefaultWorldGenerator(0, $internal))->generate(new ChunkPosition(2, -14));

        $packet = (new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $network),
        ))->serialize($chunk);

        self::assertNotSame('', $packet->data);
    }
}
