<?php

declare(strict_types=1);

namespace Bedriox\Server\Transport\Process;

use RuntimeException;

final class TransportProcessFrameDecoder
{
    private string $buffer = '';
    private int $offset = 0;

    public function __construct(
        private readonly TransportProcessFrameCodec $codec,
        private readonly int $maximumBufferedBytes = 67_108_864,
    ) {
        if ($maximumBufferedBytes < TransportProcessFrameCodec::MAXIMUM_FRAME_BYTES
            || $maximumBufferedBytes > 268_435_456) {
            throw new \InvalidArgumentException('Transport process decoder buffer limit is invalid.');
        }
    }

    public function append(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        if (strlen($bytes) > $this->maximumBufferedBytes - $this->bufferedBytes()) {
            throw new RuntimeException('Transport process IPC input exceeded its buffer limit.');
        }
        if ($this->offset > 0 && ($this->offset >= 8_388_608 || $this->offset >= intdiv(strlen($this->buffer), 2))) {
            $this->buffer = (string) substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
        $this->buffer .= $bytes;
    }

    /** @return list<TransportProcessFrame> */
    public function drain(int $maximumFrames = 4_096): array
    {
        if ($maximumFrames < 1 || $maximumFrames > 65_535) {
            throw new \InvalidArgumentException('Transport process frame drain limit is invalid.');
        }
        $frames = [];
        while (count($frames) < $maximumFrames && $this->bufferedBytes() >= 4) {
            /** @var array{length: int} $header */
            $header = unpack('Nlength', substr($this->buffer, $this->offset, 4));
            $length = $header['length'];
            if ($length < 5 || $length > TransportProcessFrameCodec::MAXIMUM_FRAME_BYTES) {
                throw new RuntimeException('Transport process IPC declared an invalid frame length.');
            }
            if ($this->bufferedBytes() < 4 + $length) {
                break;
            }
            $frames[] = $this->codec->decode(substr($this->buffer, $this->offset + 4, $length));
            $this->offset += 4 + $length;
        }
        if ($this->offset === strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
        }

        return $frames;
    }

    public function bufferedBytes(): int
    {
        return strlen($this->buffer) - $this->offset;
    }
}
