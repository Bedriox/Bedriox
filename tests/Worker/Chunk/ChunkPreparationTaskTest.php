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
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
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
        $chest = ContainerBlockEntity::empty(BlockEntityType::Chest, new BlockPosition(-47, 64, 81))
            ->withCustomName('Worker storage')
            ->withPair(new BlockPosition(-46, 64, 81), true);
        $chunk = $chunk->withBlockEntity($chest->withInventory($chest->inventory->withStack(
            2,
            new ContainerItemStack('minecraft:coal', 16),
        )));
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
        self::assertStringContainsString('Worker storage', $packet->data);
        self::assertStringContainsString('pairx', $packet->data);
        self::assertStringNotContainsString('Items', $packet->data);
    }
}
