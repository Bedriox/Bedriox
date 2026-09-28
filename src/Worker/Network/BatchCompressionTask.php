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
