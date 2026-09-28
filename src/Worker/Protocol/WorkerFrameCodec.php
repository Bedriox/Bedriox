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

use JsonException;

final class WorkerFrameCodec
{
    public const MAGIC = 'BDWX';
    public const VERSION = 1;
    public const FIXED_HEADER_BYTES = 80;
    public const MAXIMUM_METADATA_BYTES = 65_536;
    public const MAXIMUM_PAYLOAD_BYTES = 33_554_432;

    public function encode(WorkerFrame $frame): string
    {
        $metadata = $frame->metadata;
        ksort($metadata, SORT_STRING);
        try {
            $header = $metadata === []
                ? '{}'
                : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new WorkerProtocolException('Worker frame metadata could not be encoded.', 0, $exception);
        }
        if (strlen($header) > self::MAXIMUM_METADATA_BYTES || strlen($frame->payload) > self::MAXIMUM_PAYLOAD_BYTES) {
            throw new WorkerProtocolException('Worker frame exceeds protocol limits.');
        }
        $deadlineHigh = ($frame->deadlineNanoseconds >> 32) & 0xffffffff;
        $deadlineLow = $frame->deadlineNanoseconds & 0xffffffff;
        $checksum = hash('sha256', $header . $frame->payload, true);

        return pack(
            'a4nCCa16NnnNNNNa32',
            self::MAGIC,
            self::VERSION,
            $frame->kind->value,
            $frame->flags,
            $frame->epoch,
            $frame->taskId,
            $frame->taskTypeId,
            $frame->schemaVersion,
            $deadlineHigh,
            $deadlineLow,
            strlen($header),
            strlen($frame->payload),
            $checksum,
        ) . $header . $frame->payload;
    }

    public function decode(string $bytes): WorkerFrame
    {
        if (strlen($bytes) < self::FIXED_HEADER_BYTES) {
            throw new WorkerProtocolException('Worker frame is truncated.');
        }
        $fixed = unpack(
            'a4magic/nversion/Ckind/Cflags/a16epoch/Ntask/ntype/nschema/NdeadlineHigh/NdeadlineLow/NheaderLength/NpayloadLength/a32checksum',
            substr($bytes, 0, self::FIXED_HEADER_BYTES),
        );
        if (!is_array($fixed) || $this->stringField($fixed, 'magic') !== self::MAGIC || $this->intField($fixed, 'version') !== self::VERSION) {
            throw new WorkerProtocolException('Worker frame magic or version is invalid.');
        }
        $headerLength = $this->intField($fixed, 'headerLength');
        $payloadLength = $this->intField($fixed, 'payloadLength');
        if ($headerLength > self::MAXIMUM_METADATA_BYTES || $payloadLength > self::MAXIMUM_PAYLOAD_BYTES
            || strlen($bytes) !== self::FIXED_HEADER_BYTES + $headerLength + $payloadLength) {
            throw new WorkerProtocolException('Worker frame length is invalid.');
        }
        $kind = WorkerFrameKind::tryFrom($this->intField($fixed, 'kind'));
        if ($kind === null) {
            throw new WorkerProtocolException('Worker frame kind is unknown.');
        }
        $header = substr($bytes, self::FIXED_HEADER_BYTES, $headerLength);
        $payload = substr($bytes, self::FIXED_HEADER_BYTES + $headerLength, $payloadLength);
        if (!hash_equals($this->stringField($fixed, 'checksum'), hash('sha256', $header . $payload, true))) {
            throw new WorkerProtocolException('Worker frame checksum is invalid.');
        }
        try {
            $metadata = json_decode($header, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WorkerProtocolException('Worker frame metadata is invalid.', 0, $exception);
        }
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new WorkerProtocolException('Worker frame metadata must be an object.');
        }
        /** @var array<string, bool|int|string|null> $metadata */
        $deadline = ($this->intField($fixed, 'deadlineHigh') << 32) | $this->intField($fixed, 'deadlineLow');

        return new WorkerFrame(
            $kind,
            $this->stringField($fixed, 'epoch'),
            $this->intField($fixed, 'task'),
            $this->intField($fixed, 'type'),
            $this->intField($fixed, 'schema'),
            $this->intField($fixed, 'flags'),
            $deadline,
            $metadata,
            $payload,
        );
    }

    /** @return array{headerLength: int, payloadLength: int}|null */
    public function lengths(string $buffer): ?array
    {
        if (strlen($buffer) < self::FIXED_HEADER_BYTES) {
            return null;
        }
        $fixed = unpack('a4magic/nversion/x34/NheaderLength/NpayloadLength', substr($buffer, 0, 48));
        if (!is_array($fixed) || $this->stringField($fixed, 'magic') !== self::MAGIC || $this->intField($fixed, 'version') !== self::VERSION) {
            throw new WorkerProtocolException('Worker frame prefix is invalid.');
        }
        $headerLength = $this->intField($fixed, 'headerLength');
        $payloadLength = $this->intField($fixed, 'payloadLength');
        if ($headerLength > self::MAXIMUM_METADATA_BYTES || $payloadLength > self::MAXIMUM_PAYLOAD_BYTES) {
            throw new WorkerProtocolException('Worker frame declared length exceeds protocol limits.');
        }

        return ['headerLength' => $headerLength, 'payloadLength' => $payloadLength];
    }

    /** @param array<mixed> $fields */
    private function intField(array $fields, string $name): int
    {
        $value = $fields[$name] ?? null;
        if (!is_int($value)) {
            throw new WorkerProtocolException('Worker frame integer field is invalid.');
        }

        return $value;
    }

    /** @param array<mixed> $fields */
    private function stringField(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;
        if (!is_string($value)) {
            throw new WorkerProtocolException('Worker frame string field is invalid.');
        }

        return $value;
    }
}
