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

namespace Bedriox\Server\Transport\Process;

use Bedriox\NetherNet\Protocol\Reliability;
use JsonException;
use RuntimeException;

final class TransportProcessFrameCodec
{
    public const int MAXIMUM_FRAME_BYTES = 16_777_216;
    public const int MAXIMUM_METADATA_BYTES = 65_535;
    public const int PREFIX_BYTES = 4;

    public function encode(TransportProcessFrame $frame): string
    {
        if ($frame->sessionId < 0 || $frame->sessionId > 0xffff_ffff) {
            throw new RuntimeException('Transport process session identifier is out of range.');
        }
        $body = chr($frame->kind->value) . pack('N', $frame->sessionId);
        if ($frame->kind === TransportProcessFrameKind::SEND_PAYLOAD
            || $frame->kind === TransportProcessFrameKind::RECEIVED_PAYLOAD) {
            if ($frame->payload === '' || $frame->reliability === null) {
                throw new RuntimeException('Transport payload frame metadata is invalid.');
            }
            $body .= chr(self::encodeReliability($frame->reliability));
            if ($frame->kind === TransportProcessFrameKind::SEND_PAYLOAD) {
                if ($frame->receiptId !== null && ($frame->receiptId < 1 || $frame->receiptId > 0x7fff_ffff)) {
                    throw new RuntimeException('Transport payload receipt identifier is invalid.');
                }
                $body .= pack('N', $frame->receiptId ?? 0);
            }
            $body .= $frame->payload;
        } elseif ($frame->metadata !== [] || $frame->payload !== '') {
            try {
                $metadata = json_encode(
                    $frame->metadata + ($frame->payload !== '' ? ['detail' => $frame->payload] : []),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                );
            } catch (JsonException $exception) {
                throw new RuntimeException('Transport process metadata cannot be encoded.', 0, $exception);
            }
            if (strlen($metadata) > self::MAXIMUM_METADATA_BYTES) {
                throw new RuntimeException('Transport process metadata exceeds its size limit.');
            }
            $body .= pack('n', strlen($metadata)) . $metadata;
        }
        if (strlen($body) < 5 || strlen($body) > self::MAXIMUM_FRAME_BYTES) {
            throw new RuntimeException('Transport process frame length is invalid.');
        }

        return pack('N', strlen($body)) . $body;
    }

    public function decode(string $body): TransportProcessFrame
    {
        $length = strlen($body);
        if ($length < 5 || $length > self::MAXIMUM_FRAME_BYTES) {
            throw new RuntimeException('Transport process frame length is invalid.');
        }
        $kind = TransportProcessFrameKind::tryFrom(ord($body[0]));
        if ($kind === null) {
            throw new RuntimeException('Transport process frame kind is unknown.');
        }
        /** @var array{session: int} $decoded */
        $decoded = unpack('Nsession', substr($body, 1, 4));
        $sessionId = $decoded['session'];
        if ($kind === TransportProcessFrameKind::SEND_PAYLOAD
            || $kind === TransportProcessFrameKind::RECEIVED_PAYLOAD) {
            $minimumLength = $kind === TransportProcessFrameKind::SEND_PAYLOAD ? 11 : 7;
            if ($length < $minimumLength) {
                throw new RuntimeException('Transport payload frame is truncated.');
            }
            $reliability = self::decodeReliability(ord($body[5]));
            $receiptId = null;
            $payloadOffset = 6;
            if ($kind === TransportProcessFrameKind::SEND_PAYLOAD) {
                /** @var array{receipt: int} $decodedReceipt */
                $decodedReceipt = unpack('Nreceipt', substr($body, 6, 4));
                if ($decodedReceipt['receipt'] > 0x7fff_ffff) {
                    throw new RuntimeException('Transport payload receipt identifier is invalid.');
                }
                $receiptId = $decodedReceipt['receipt'] === 0 ? null : $decodedReceipt['receipt'];
                $payloadOffset = 10;
            }
            $payload = substr($body, $payloadOffset);
            if ($reliability === null || $payload === '') {
                throw new RuntimeException('Transport payload frame is invalid.');
            }

            return new TransportProcessFrame($kind, $sessionId, $payload, $reliability, receiptId: $receiptId);
        }
        if ($length === 5) {
            return new TransportProcessFrame($kind, $sessionId);
        }
        if ($length < 7) {
            throw new RuntimeException('Transport metadata frame is truncated.');
        }
        /** @var array{length: int} $metadataHeader */
        $metadataHeader = unpack('nlength', substr($body, 5, 2));
        $metadataLength = $metadataHeader['length'];
        if ($metadataLength !== $length - 7) {
            throw new RuntimeException('Transport metadata frame length is inconsistent.');
        }
        try {
            $metadata = json_decode(substr($body, 7), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Transport process metadata cannot be decoded.', 0, $exception);
        }
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new RuntimeException('Transport process metadata must decode to an object.');
        }
        $validatedMetadata = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key)
                || (!is_array($value) && !is_bool($value) && !is_int($value) && !is_string($value) && $value !== null)) {
                throw new RuntimeException('Transport process metadata contains an invalid value.');
            }
            $validatedMetadata[$key] = $value;
        }

        return new TransportProcessFrame($kind, $sessionId, metadata: $validatedMetadata);
    }

    private static function encodeReliability(Reliability $reliability): int
    {
        return match ($reliability) {
            Reliability::RELIABLE => 0,
            Reliability::UNRELIABLE => 1,
        };
    }

    private static function decodeReliability(int $value): ?Reliability
    {
        return match ($value) {
            0 => Reliability::RELIABLE,
            1 => Reliability::UNRELIABLE,
            default => null,
        };
    }
}
