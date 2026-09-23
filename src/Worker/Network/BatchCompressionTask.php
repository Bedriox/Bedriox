<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

use Bedriox\Protocol\Batch\BatchCompressionCodec;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Server\Worker\WorkerTaskHandler;

final class BatchCompressionTask implements WorkerTaskHandler
{
    public function execute(string $payload): string
    {
        $request = (new BatchCompressionRequestCodec())->decode($payload);

        return chr(BedrockBatchCodec::GAME_PACKET_MARKER) . BatchCompressionCodec::encode(
            $request->uncompressedBatch,
            $request->mode,
            $request->limits,
            $request->threshold,
        );
    }
}
