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

namespace Bedriox\Server\Worker\Protocol;

final class WorkerFrameDecoder
{
    private string $buffer = '';

    public function __construct(
        private readonly WorkerFrameCodec $codec = new WorkerFrameCodec(),
        private readonly int $maximumBufferedBytes = 134_217_728,
    ) {
        if ($maximumBufferedBytes < WorkerFrameCodec::FIXED_HEADER_BYTES) {
            throw new \InvalidArgumentException('Worker frame buffer is too small.');
        }
    }

    /** @return list<WorkerFrame> */
    public function push(string $bytes, int $maximumFrames = 256): array
    {
        if ($maximumFrames < 1 || $maximumFrames > 256) {
            throw new WorkerProtocolException('Worker frame drain limit is invalid.');
        }
        $this->append($bytes);

        return $this->drain($maximumFrames);
    }

    public function append(string $bytes): void
    {
        if (strlen($this->buffer) + strlen($bytes) > $this->maximumBufferedBytes) {
            throw new WorkerProtocolException('Worker frame buffer limit exceeded.');
        }
        $this->buffer .= $bytes;
    }

    /** @return list<WorkerFrame> */
    public function drain(int $maximumFrames = 256, ?int $maximumPayloadBytes = null): array
    {
        if ($maximumFrames < 1 || $maximumFrames > 256 || ($maximumPayloadBytes !== null && $maximumPayloadBytes < 0)) {
            throw new WorkerProtocolException('Worker frame drain limit is invalid.');
        }
        $frames = [];
        $payloadBytes = 0;
        while (count($frames) < $maximumFrames) {
            $lengths = $this->codec->lengths($this->buffer);
            if ($lengths === null) {
                break;
            }
            $length = WorkerFrameCodec::FIXED_HEADER_BYTES + $lengths['headerLength'] + $lengths['payloadLength'];
            if (strlen($this->buffer) < $length) {
                break;
            }
            $frame = $this->codec->decode(substr($this->buffer, 0, $length));
            if ($maximumPayloadBytes !== null && strlen($frame->payload) > $maximumPayloadBytes - $payloadBytes) {
                break;
            }
            $frames[] = $frame;
            $payloadBytes += strlen($frame->payload);
            $this->buffer = substr($this->buffer, $length);
        }

        return $frames;
    }

    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    public function remainingCapacity(): int
    {
        return $this->maximumBufferedBytes - strlen($this->buffer);
    }
}
