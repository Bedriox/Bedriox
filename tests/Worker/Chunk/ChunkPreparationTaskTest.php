<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\LevelChunkPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Worker\Chunk\ChunkPreparationRequest;
use Bedriox\Server\Worker\Chunk\ChunkPreparationRequestCodec;
use Bedriox\Server\Worker\Chunk\ChunkProjectionIdentity;
use Bedriox\Server\Worker\Chunk\ChunkProjectionTransferCodec;
use Bedriox\Server\Worker\Task\PrepareChunkTask;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\FlatWorldGenerator;
use PHPUnit\Framework\TestCase;

final class ChunkPreparationTaskTest extends TestCase
{
    public function testWorkerProducesACompleteCompressedLevelChunkEnvelope(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $chunk = (new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($states)))
            ->generate(new ChunkPosition(-3, 5));
        $request = new ChunkPreparationRequest(
            ProtocolVersion::CURRENT,
            ChunkProjectionIdentity::SERIALIZER_VERSION,
            ChunkProjectionIdentity::COMPRESSION_THRESHOLD,
            ChunkProjectionIdentity::bundledRegistryHash(),
            (new ChunkProjectionTransferCodec())->encode($chunk, $states),
        );

        $envelope = (new PrepareChunkTask())->execute((new ChunkPreparationRequestCodec())->encode($request));
        $batch = BedrockBatchCodec::decode(
            $envelope,
            CompressionMode::NegotiatedZlib,
            new BatchLimits(),
            ChunkProjectionIdentity::COMPRESSION_THRESHOLD,
        );

        self::assertCount(1, $batch->packets);
        $packet = BedrockPacketCodec::decode(
            $batch->packets[0]->header->packetId,
            $batch->packets[0]->payload,
            ProtocolVersion::CURRENT,
        );
        self::assertInstanceOf(LevelChunkPacket::class, $packet);
        self::assertSame(-3, $packet->chunkX);
        self::assertSame(5, $packet->chunkZ);
    }
}
