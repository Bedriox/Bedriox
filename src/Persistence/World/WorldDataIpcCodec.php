<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World;

use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldMetadata;
use JsonException;
use RuntimeException;

final class WorldDataIpcCodec
{
    public const int MAXIMUM_BYTES = 65_536;

    public function encode(WorldData $data): string
    {
        try {
            $encoded = json_encode([
                'difficulty' => $data->difficulty,
                'generator' => $data->generatorName,
                'generator_options' => $data->generatorOptions,
                'generator_version' => $data->generatorVersion,
                'name' => $data->metadata->name,
                'seed' => $data->metadata->seed,
                'spawn' => [$data->spawn->x, $data->spawn->y, $data->spawn->z],
                'time' => $data->time,
                'version' => 2,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new RuntimeException('World data IPC value could not be encoded.', previous: $error);
        }
        if (strlen($encoded) > self::MAXIMUM_BYTES) {
            throw new RuntimeException('World data IPC value exceeds its size limit.');
        }

        return $encoded;
    }

    public function decode(string $encoded): WorldData
    {
        if (strlen($encoded) > self::MAXIMUM_BYTES) {
            throw new RuntimeException('World data IPC value exceeds its size limit.');
        }
        try {
            $value = json_decode($encoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('World data IPC value is malformed.', previous: $error);
        }
        if (!is_array($value) || array_keys($value) !== [
            'difficulty', 'generator', 'generator_options', 'generator_version', 'name', 'seed', 'spawn', 'time', 'version',
        ] || $value['version'] !== 2 || !is_int($value['difficulty'])
            || !is_string($value['generator']) || !is_int($value['generator_version'])
            || !is_string($value['generator_options'])
            || !is_string($value['name']) || !is_int($value['seed'])
            || !is_array($value['spawn']) || !array_is_list($value['spawn']) || count($value['spawn']) !== 3
            || !is_int($value['spawn'][0]) || !is_int($value['spawn'][1]) || !is_int($value['spawn'][2])
            || !is_int($value['time'])) {
            throw new RuntimeException('World data IPC value has an invalid shape.');
        }

        return new WorldData(
            new WorldMetadata($value['name'], $value['seed']),
            $value['generator'],
            new SpawnPosition($value['spawn'][0], $value['spawn'][1], $value['spawn'][2]),
            $value['time'],
            $value['difficulty'],
            $value['generator_version'],
            $value['generator_options'],
        );
    }
}
