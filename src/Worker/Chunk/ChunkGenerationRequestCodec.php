<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

use Bedriox\Server\World\ChunkPosition;

final class ChunkGenerationRequestCodec
{
    public const int MAXIMUM_ENCODED_BYTES = 36_864;
    private const string MAGIC = "BXGR\x00\x01";

    public function encode(ChunkGenerationRequest $request): string
    {
        $body = json_encode([
            'generator' => $request->generator,
            'generatorVersion' => $request->generatorVersion,
            'seed' => $request->seed,
            'dimension' => $request->dimension,
            'chunkX' => $request->position->x,
            'chunkZ' => $request->position->z,
            'options' => $request->options->values(),
            'workerSource' => $request->workerSource === null ? null : [
                'class' => $request->workerSource->class,
                'file' => $request->workerSource->file,
                'sha256' => $request->workerSource->sha256,
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = self::MAGIC . pack('N', strlen($body)) . $body . hash('sha256', $body, true);
        if (strlen($encoded) > self::MAXIMUM_ENCODED_BYTES) {
            throw new ChunkTransferException('Chunk generation request exceeds its byte limit.');
        }

        return $encoded;
    }

    public function decode(string $encoded): ChunkGenerationRequest
    {
        $headerBytes = strlen(self::MAGIC) + 4;
        if (strlen($encoded) < $headerBytes + 32 || strlen($encoded) > self::MAXIMUM_ENCODED_BYTES
            || substr($encoded, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new ChunkTransferException('Chunk generation request header or size is invalid.');
        }
        $unpacked = unpack('Nvalue', substr($encoded, strlen(self::MAGIC), 4));
        $length = is_array($unpacked) ? ($unpacked['value'] ?? null) : null;
        if (!is_int($length) || $length < 2 || $headerBytes + $length + 32 !== strlen($encoded)) {
            throw new ChunkTransferException('Chunk generation request body length is invalid.');
        }
        $body = substr($encoded, $headerBytes, $length);
        if (!hash_equals(hash('sha256', $body, true), substr($encoded, $headerBytes + $length))) {
            throw new ChunkTransferException('Chunk generation request checksum does not match.');
        }
        try {
            $value = json_decode($body, true, \Bedriox\Server\World\Generator\GeneratorOptions::MAXIMUM_DEPTH + 3, JSON_THROW_ON_ERROR);
            if (!is_array($value) || array_is_list($value) || count($value) !== 8
                || !is_string($value['generator'] ?? null) || !is_int($value['generatorVersion'] ?? null)
                || !is_int($value['seed'] ?? null) || !is_string($value['dimension'] ?? null)
                || !is_int($value['chunkX'] ?? null) || !is_int($value['chunkZ'] ?? null)
                || !is_array($value['options'] ?? null)
                || !(($value['workerSource'] ?? null) === null || is_array($value['workerSource']))) {
                throw new ChunkTransferException('Chunk generation request has an invalid shape.');
            }

            $workerSource = null;
            if (is_array($value['workerSource'])) {
                $source = $value['workerSource'];
                if (array_keys($source) !== ['class', 'file', 'sha256']
                    || !is_string($source['class']) || !is_string($source['file']) || !is_string($source['sha256'])) {
                    throw new ChunkTransferException('Chunk generation worker source is invalid.');
                }
                $workerSource = new \Bedriox\Server\World\Generator\WorkerGeneratorSource(
                    $source['class'],
                    $source['file'],
                    $source['sha256'],
                );
            }

            return new ChunkGenerationRequest(
                $value['generator'],
                $value['generatorVersion'],
                $value['seed'],
                $value['dimension'],
                new ChunkPosition($value['chunkX'], $value['chunkZ']),
                new \Bedriox\Server\World\Generator\GeneratorOptions($value['options']),
                $workerSource,
            );
        } catch (ChunkTransferException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ChunkTransferException('Chunk generation request is invalid.', previous: $error);
        }
    }
}
