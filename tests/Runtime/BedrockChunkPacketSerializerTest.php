<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\LevelChunkPacket;
use Bedriox\Server\Runtime\BedrockChunkPacketSerializer;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
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
            $data->plainsBiomeRuntimeId(),
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
        $serializer = new BedrockChunkPacketSerializer($translator, $data->plainsBiomeRuntimeId());
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
}
