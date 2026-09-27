<?php

declare(strict_types=1);

namespace Bedriox\Server\Transport\Process;

use Bedriox\RakNet\Protocol\Reliability;
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
            if ($frame->payload === '' || $frame->reliability === null
                || ($frame->reliability === Reliability::ReliableOrdered) !== ($frame->orderingChannel !== null)
                || ($frame->orderingChannel !== null
                    && ($frame->orderingChannel < 0 || $frame->orderingChannel > 31))) {
                throw new RuntimeException('Transport payload frame metadata is invalid.');
            }
            $body .= chr($frame->reliability->value)
                . chr($frame->orderingChannel ?? 0xff)
                . $frame->payload;
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
            if ($length < 8) {
                throw new RuntimeException('Transport payload frame is truncated.');
            }
            $reliability = Reliability::tryFrom(ord($body[5]));
            $channelByte = ord($body[6]);
            $orderingChannel = $channelByte === 0xff ? null : $channelByte;
            $payload = substr($body, 7);
            if ($reliability === null || $payload === ''
                || ($reliability === Reliability::ReliableOrdered) !== ($orderingChannel !== null)
                || ($orderingChannel !== null && $orderingChannel > 31)) {
                throw new RuntimeException('Transport payload frame is invalid.');
            }

            return new TransportProcessFrame($kind, $sessionId, $payload, $reliability, $orderingChannel);
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
}
