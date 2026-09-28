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

use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Bedriox\Server\Worker\Protocol\WorkerProtocolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerFrameCodecTest extends TestCase
{
    public function testLiteralFieldsRoundTripWithoutPhpSerialization(): void
    {
        $codec = new WorkerFrameCodec();
        $epoch = str_repeat("\x41", 16);
        $encoded = $codec->encode(new WorkerFrame(
            WorkerFrameKind::SUBMIT,
            $epoch,
            42,
            7,
            3,
            5,
            9_876_543_210,
            ['lane' => 2, 'owner' => 'world'],
            "\x00binary\xff",
        ));
        self::assertStringNotContainsString('O:', $encoded);
        $decoded = $codec->decode($encoded);
        self::assertSame(WorkerFrameKind::SUBMIT, $decoded->kind);
        self::assertSame($epoch, $decoded->epoch);
        self::assertSame(42, $decoded->taskId);
        self::assertSame(7, $decoded->taskTypeId);
        self::assertSame(3, $decoded->schemaVersion);
        self::assertSame(5, $decoded->flags);
        self::assertSame(9_876_543_210, $decoded->deadlineNanoseconds);
        self::assertSame(['lane' => 2, 'owner' => 'world'], $decoded->metadata);
        self::assertSame("\x00binary\xff", $decoded->payload);
    }

    #[DataProvider('fragmentSizes')]
    public function testDecoderAcceptsEveryFragmentBoundary(int $fragmentSize): void
    {
        self::assertGreaterThan(0, $fragmentSize);
        $fragmentSize = max(1, $fragmentSize);
        $codec = new WorkerFrameCodec();
        $encoded = $codec->encode(new WorkerFrame(
            WorkerFrameKind::RESULT,
            str_repeat("\x11", 16),
            1,
            2,
            3,
            payload: str_repeat('x', 257),
        ));
        $decoder = new WorkerFrameDecoder($codec, 1_024);
        $frames = [];
        foreach (str_split($encoded, $fragmentSize) as $fragment) {
            $frames = [...$frames, ...$decoder->push($fragment)];
        }
        self::assertCount(1, $frames);
        self::assertSame(str_repeat('x', 257), $frames[0]->payload);
        self::assertSame(0, $decoder->bufferedBytes());
    }

    /** @return iterable<string, array{int}> */
    public static function fragmentSizes(): iterable
    {
        foreach ([1, 2, 3, 7, 31, 79, 80, 127] as $size) {
            yield (string) $size => [$size];
        }
    }

    public function testChecksumMutationIsRejected(): void
    {
        $codec = new WorkerFrameCodec();
        $encoded = $codec->encode(new WorkerFrame(
            WorkerFrameKind::RESULT,
            str_repeat("\x22", 16),
            payload: 'payload',
        ));
        $encoded[strlen($encoded) - 1] = 'X';

        $this->expectException(WorkerProtocolException::class);
        $codec->decode($encoded);
    }

    public function testDeclaredOversizeIsRejectedBeforeBodyArrival(): void
    {
        $codec = new WorkerFrameCodec();
        $encoded = $codec->encode(new WorkerFrame(WorkerFrameKind::HEARTBEAT, str_repeat("\x33", 16)));
        $encoded = substr_replace($encoded, pack('N', WorkerFrameCodec::MAXIMUM_PAYLOAD_BYTES + 1), 44, 4);

        $this->expectException(WorkerProtocolException::class);
        (new WorkerFrameDecoder())->push($encoded);
    }

    public function testBufferedBurstDrainsWithoutAdditionalSocketBytes(): void
    {
        $codec = new WorkerFrameCodec();
        $epoch = str_repeat("\x44", 16);
        $encoded = '';
        for ($taskId = 1; $taskId <= 300; ++$taskId) {
            $encoded .= $codec->encode(new WorkerFrame(
                WorkerFrameKind::RESULT,
                $epoch,
                $taskId,
                1,
                1,
            ));
        }

        $decoder = new WorkerFrameDecoder($codec, strlen($encoded));
        self::assertCount(256, $decoder->push($encoded, 256));
        self::assertGreaterThan(0, $decoder->bufferedBytes());
        self::assertCount(44, $decoder->drain(256));
        self::assertSame(0, $decoder->bufferedBytes());
    }

    public function testPayloadBudgetLeavesTheNextCompleteFrameBuffered(): void
    {
        $codec = new WorkerFrameCodec();
        $epoch = str_repeat("\x55", 16);
        $decoder = new WorkerFrameDecoder($codec, 1_024);
        $decoder->append(
            $codec->encode(new WorkerFrame(WorkerFrameKind::RESULT, $epoch, 1, payload: '1234'))
            . $codec->encode(new WorkerFrame(WorkerFrameKind::RESULT, $epoch, 2, payload: '5678')),
        );

        $first = $decoder->drain(256, 4);
        self::assertCount(1, $first);
        self::assertSame(1, $first[0]->taskId);
        self::assertGreaterThan(0, $decoder->bufferedBytes());
        $second = $decoder->drain(256, 4);
        self::assertCount(1, $second);
        self::assertSame(2, $second[0]->taskId);
    }
}
