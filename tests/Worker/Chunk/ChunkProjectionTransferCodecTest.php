<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\ChunkSerializer;
use Bedriox\Server\Runtime\BedrockChunkPacketSerializer;
use Bedriox\Server\Worker\Chunk\ChunkProjectionTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferException;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\FlatWorldGenerator;
use PHPUnit\Framework\TestCase;

final class ChunkProjectionTransferCodecTest extends TestCase
{
    public function testPackedProjectionMatchesTheAuthoritativeSerializerAndShrinksWorkerInput(): void
    {
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $states = new BlockStateRegistry($networkStates->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $chunk = (new FlatWorldGenerator($palette))->generate(new ChunkPosition(-9, 12))
            ->withBlockState(3, 64, 7, $palette->dirt);
        $blocks = new BlockNetworkTranslator($states, $networkStates);
        $codec = new ChunkProjectionTransferCodec();

        $packed = $codec->encode($chunk, $states);
        $column = $codec->decodeColumn($packed, $states, $blocks, $data->plainsBiomeRuntimeId());

        self::assertEquals(
            (new BedrockChunkPacketSerializer($blocks, $data->plainsBiomeRuntimeId()))->serialize($chunk),
            ChunkSerializer::fullColumn($column),
        );
        self::assertLessThan(strlen((new ChunkTransferCodec())->encode($chunk, $states)) / 4, strlen($packed));
    }

    public function testCorruptTruncatedAndTrailingProjectionsFailClosed(): void
    {
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $states = new BlockStateRegistry($networkStates->states());
        $chunk = (new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($states)))
            ->generate(new ChunkPosition(0, 0));
        $blocks = new BlockNetworkTranslator($states, $networkStates);
        $codec = new ChunkProjectionTransferCodec();
        $encoded = $codec->encode($chunk, $states);
        $corrupt = $encoded;
        $corrupt[20] = chr(ord($corrupt[20]) ^ 1);

        foreach ([$corrupt, substr($encoded, 0, -1), $encoded . "\0"] as $invalid) {
            try {
                $codec->decodeColumn($invalid, $states, $blocks, $data->plainsBiomeRuntimeId());
                self::fail('A malformed chunk projection was accepted.');
            } catch (ChunkTransferException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
