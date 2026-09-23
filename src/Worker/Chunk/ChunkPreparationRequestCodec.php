<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

use InvalidArgumentException;

final class ChunkPreparationRequestCodec
{
    public const int MAXIMUM_ENCODED_BYTES = 16_777_216;
    private const string MAGIC = "BXCP\x00\x01";
    private const int HEADER_BYTES = 50;

    public function encode(ChunkPreparationRequest $request): string
    {
        $registryHash = hex2bin($request->registryHash);
        $chunkBytes = strlen($request->chunkTransfer);
        if (!is_string($registryHash) || strlen($registryHash) !== 32
            || $chunkBytes < 1 || self::HEADER_BYTES + $chunkBytes > self::MAXIMUM_ENCODED_BYTES) {
            throw new InvalidArgumentException('Chunk-preparation request exceeds its byte limit.');
        }

        return self::MAGIC
            . pack('NnnN', $request->protocolVersion, $request->serializerVersion, $request->compressionThreshold, $chunkBytes)
            . $registryHash
            . $request->chunkTransfer;
    }

    public function decode(string $payload): ChunkPreparationRequest
    {
        if (strlen($payload) < self::HEADER_BYTES || strlen($payload) > self::MAXIMUM_ENCODED_BYTES
            || substr($payload, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new InvalidArgumentException('Chunk-preparation request header is invalid.');
        }
        $fields = unpack('Nprotocol/nserializer/nthreshold/Nlength', substr($payload, 6, 12));
        if (!is_array($fields)) {
            throw new InvalidArgumentException('Chunk-preparation request fields are invalid.');
        }
        $protocol = $fields['protocol'] ?? null;
        $serializer = $fields['serializer'] ?? null;
        $threshold = $fields['threshold'] ?? null;
        $length = $fields['length'] ?? null;
        if (!is_int($protocol) || !is_int($serializer) || !is_int($threshold) || !is_int($length)
            || $length < 1 || self::HEADER_BYTES + $length !== strlen($payload)) {
            throw new InvalidArgumentException('Chunk-preparation request length is invalid.');
        }

        return new ChunkPreparationRequest(
            $protocol,
            $serializer,
            $threshold,
            bin2hex(substr($payload, 18, 32)),
            substr($payload, self::HEADER_BYTES, $length),
        );
    }
}
