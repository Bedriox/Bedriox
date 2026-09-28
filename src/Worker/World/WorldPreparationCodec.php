<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\World;

use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\WorkerGeneratorSource;
use Bedriox\Server\World\SpawnPosition;
use JsonException;
use RuntimeException;
use Throwable;

/** Bounded, checksummed IPC contract for world preparation. */
final class WorldPreparationCodec
{
    public const int MAXIMUM_REQUEST_BYTES = 36_864;
    public const int MAXIMUM_RESULT_BYTES = 512;
    private const string REQUEST_MAGIC = "BXWP\x00\x01";
    private const string RESULT_MAGIC = "BXWR\x00\x01";

    public function encodeRequest(WorldPreparationRequest $request): string
    {
        return $this->encode(self::REQUEST_MAGIC, [
            'generator' => $request->generator,
            'generatorIdentifier' => $request->generatorIdentifier,
            'generatorVersion' => $request->generatorVersion,
            'seed' => $request->seed,
            'dimension' => $request->dimension,
            'options' => $request->options->values(),
            'workerSource' => $request->workerSource === null ? null : [
                'class' => $request->workerSource->class,
                'file' => $request->workerSource->file,
                'sha256' => $request->workerSource->sha256,
            ],
        ], self::MAXIMUM_REQUEST_BYTES);
    }

    public function decodeRequest(string $encoded): WorldPreparationRequest
    {
        $value = $this->decode($encoded, self::REQUEST_MAGIC, self::MAXIMUM_REQUEST_BYTES, GeneratorOptions::MAXIMUM_DEPTH + 3);
        if (count($value) !== 7
            || !is_string($value['generator'] ?? null)
            || !is_string($value['generatorIdentifier'] ?? null)
            || !is_int($value['generatorVersion'] ?? null)
            || !is_int($value['seed'] ?? null)
            || !is_string($value['dimension'] ?? null)
            || !is_array($value['options'] ?? null)
            || !(($value['workerSource'] ?? null) === null || is_array($value['workerSource']))) {
            throw new RuntimeException('World preparation request has an invalid shape.');
        }
        $source = null;
        if (is_array($value['workerSource'])) {
            $encodedSource = $value['workerSource'];
            if (array_keys($encodedSource) !== ['class', 'file', 'sha256']
                || !is_string($encodedSource['class']) || !is_string($encodedSource['file'])
                || !is_string($encodedSource['sha256'])) {
                throw new RuntimeException('World preparation worker source is invalid.');
            }
            $source = new WorkerGeneratorSource(
                $encodedSource['class'],
                $encodedSource['file'],
                $encodedSource['sha256'],
            );
        }

        return new WorldPreparationRequest(
            $value['generator'],
            $value['generatorIdentifier'],
            $value['generatorVersion'],
            $value['seed'],
            $value['dimension'],
            new GeneratorOptions($value['options']),
            $source,
        );
    }

    public function encodeResult(WorldPreparationResult $result): string
    {
        return $this->encode(self::RESULT_MAGIC, [
            'generatorIdentifier' => $result->generatorIdentifier,
            'generatorVersion' => $result->generatorVersion,
            'spawn' => [$result->defaultSpawn->x, $result->defaultSpawn->y, $result->defaultSpawn->z],
        ], self::MAXIMUM_RESULT_BYTES);
    }

    public function decodeResult(string $encoded): WorldPreparationResult
    {
        $value = $this->decode($encoded, self::RESULT_MAGIC, self::MAXIMUM_RESULT_BYTES, 4);
        $spawn = $value['spawn'] ?? null;
        if (count($value) !== 3
            || !is_string($value['generatorIdentifier'] ?? null)
            || !is_int($value['generatorVersion'] ?? null)
            || !is_array($spawn) || count($spawn) !== 3
            || !is_int($spawn[0] ?? null) || !is_int($spawn[1] ?? null) || !is_int($spawn[2] ?? null)) {
            throw new RuntimeException('World preparation result has an invalid shape.');
        }

        return new WorldPreparationResult(
            $value['generatorIdentifier'],
            $value['generatorVersion'],
            new SpawnPosition($spawn[0], $spawn[1], $spawn[2]),
        );
    }

    /** @param array<string, mixed> $value */
    private function encode(string $magic, array $value, int $maximumBytes): string
    {
        try {
            $body = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new RuntimeException('World preparation payload could not be encoded.', previous: $error);
        }
        $encoded = $magic . pack('N', strlen($body)) . $body . hash('sha256', $body, true);
        if (strlen($encoded) > $maximumBytes) {
            throw new RuntimeException('World preparation payload exceeds its byte limit.');
        }

        return $encoded;
    }

    /** @return array<string, mixed> */
    private function decode(string $encoded, string $magic, int $maximumBytes, int $depth): array
    {
        if ($depth < 1) {
            throw new RuntimeException('World preparation payload depth is invalid.');
        }
        $headerBytes = strlen($magic) + 4;
        if (strlen($encoded) < $headerBytes + 34 || strlen($encoded) > $maximumBytes
            || substr($encoded, 0, strlen($magic)) !== $magic) {
            throw new RuntimeException('World preparation payload header or size is invalid.');
        }
        $unpacked = unpack('Nvalue', substr($encoded, strlen($magic), 4));
        $length = is_array($unpacked) ? ($unpacked['value'] ?? null) : null;
        if (!is_int($length) || $length < 2 || $headerBytes + $length + 32 !== strlen($encoded)) {
            throw new RuntimeException('World preparation payload length is invalid.');
        }
        $body = substr($encoded, $headerBytes, $length);
        if (!hash_equals(hash('sha256', $body, true), substr($encoded, $headerBytes + $length))) {
            throw new RuntimeException('World preparation payload checksum does not match.');
        }
        try {
            $value = json_decode($body, true, $depth, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('World preparation payload is invalid.', previous: $error);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('World preparation payload must be an object.');
        }
        $object = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new RuntimeException('World preparation payload keys must be strings.');
            }
            $object[$key] = $entry;
        }

        return $object;
    }
}
