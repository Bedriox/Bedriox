<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\CompressionMode;
use InvalidArgumentException;

final class BatchCompressionRequestCodec
{
    private const string MAGIC = "BCP\x01";
    private const int HEADER_BYTES = 27;

    public function encode(BatchCompressionRequest $request): string
    {
        $mode = array_search($request->mode, CompressionMode::cases(), true);
        if (!is_int($mode) || strlen($request->uncompressedBatch) > $request->limits->maximumDecompressedBytes) {
            throw new InvalidArgumentException('Compression request is outside its declared limits.');
        }

        return self::MAGIC
            . chr($mode)
            . pack(
                'nNNnnNN',
                $request->threshold,
                $request->limits->maximumInputBytes,
                $request->limits->maximumDecompressedBytes,
                $request->limits->maximumCompressionRatio,
                $request->limits->maximumPackets,
                $request->limits->maximumPacketBytes,
                strlen($request->uncompressedBatch),
            )
            . $request->uncompressedBatch;
    }

    public function decode(string $payload): BatchCompressionRequest
    {
        if (strlen($payload) < self::HEADER_BYTES || substr($payload, 0, 4) !== self::MAGIC) {
            throw new InvalidArgumentException('Compression request header is invalid.');
        }
        $modeIndex = ord($payload[4]);
        $fields = unpack('nthreshold/Ninput/Ndecompressed/nratio/npackets/Npacket/Nlength', substr($payload, 5, 22));
        if (!is_array($fields)) {
            throw new InvalidArgumentException('Compression request limits are invalid.');
        }
        $mode = CompressionMode::cases()[$modeIndex] ?? null;
        $threshold = $fields['threshold'];
        $input = $fields['input'];
        $decompressed = $fields['decompressed'];
        $ratio = $fields['ratio'];
        $packets = $fields['packets'];
        $packet = $fields['packet'];
        $length = $fields['length'];
        if (!is_int($threshold) || !is_int($input) || !is_int($decompressed) || !is_int($ratio)
            || !is_int($packets) || !is_int($packet) || !is_int($length) || $mode === null
            || $length < 1 || strlen($payload) !== self::HEADER_BYTES + $length) {
            throw new InvalidArgumentException('Compression request fields are invalid.');
        }

        return new BatchCompressionRequest(
            substr($payload, self::HEADER_BYTES, $length),
            $mode,
            $threshold,
            new BatchLimits($input, $decompressed, $ratio, $packets, $packet),
        );
    }
}
