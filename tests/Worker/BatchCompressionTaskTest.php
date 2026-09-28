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

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Batch\PacketBatchCodec;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Server\Worker\Network\BatchCompressionRequest;
use Bedriox\Server\Worker\Network\BatchCompressionRequestCodec;
use Bedriox\Server\Worker\Network\BatchCompressionTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BatchCompressionTaskTest extends TestCase
{
    #[DataProvider('modes')]
    public function testWorkerResultIsByteIdenticalToSynchronousCodec(CompressionMode $mode, int $threshold): void
    {
        $limits = new BatchLimits();
        $frames = [new PacketFrame(new PacketHeader(9), str_repeat('chunk-data-', 100))];
        $request = new BatchCompressionRequest(
            PacketBatchCodec::encode($frames, $limits),
            $mode,
            $threshold,
            $limits,
        );
        $codec = new BatchCompressionRequestCodec();

        self::assertSame(
            BedrockBatchCodec::encode(new BedrockBatch($frames, $mode, $threshold), $limits),
            (new BatchCompressionTask())->execute($codec->encode($request)),
        );
    }

    /** @return iterable<string, array{CompressionMode, int}> */
    public static function modes(): iterable
    {
        yield 'uncompressed' => [CompressionMode::Uncompressed, 1];
        yield 'zlib' => [CompressionMode::Zlib, 1];
        yield 'prefixed none' => [CompressionMode::PrefixedNone, 1];
        yield 'negotiated compressed' => [CompressionMode::NegotiatedZlib, 64];
        yield 'negotiated plain' => [CompressionMode::NegotiatedZlib, 4096];
    }

    public function testCodecRejectsTrailingData(): void
    {
        $codec = new BatchCompressionRequestCodec();
        $request = new BatchCompressionRequest('packet', CompressionMode::NegotiatedZlib, 1, new BatchLimits());

        $this->expectException(\InvalidArgumentException::class);
        $codec->decode($codec->encode($request) . 'trailing');
    }
}
