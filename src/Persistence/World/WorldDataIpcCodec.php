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

namespace Bedriox\Server\Persistence\World;

use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use Bedriox\Server\World\Provider\DimensionWorldData;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WeatherCycleState;
use Bedriox\Server\World\WorldMetadata;
use JsonException;
use RuntimeException;

final class WorldDataIpcCodec
{
    public const int MAXIMUM_BYTES = 65_536;

    public function encode(WorldData $data): string
    {
        $dimensions = [];
        foreach (DimensionType::cases() as $type) {
            $dimension = $data->dimension($type);
            $dimensions[$type->value] = [
                'declared' => $dimension->bedrioxGeneratorVersionDeclared,
                'generator' => $dimension->generatorName,
                'options' => $dimension->generatorOptions,
                'seed' => $dimension->seed,
                'spawn' => [$dimension->spawn->x, $dimension->spawn->y, $dimension->spawn->z],
                'version' => $dimension->generatorVersion,
            ];
        }
        try {
            $encoded = json_encode([
                'difficulty' => $data->difficulty,
                'dimensions' => $dimensions,
                'generator' => $data->generatorName,
                'generator_options' => $data->generatorOptions,
                'generator_version_declared' => $data->bedrioxGeneratorVersionDeclared,
                'generator_version' => $data->generatorVersion,
                'name' => $data->metadata->name,
                'seed' => $data->metadata->seed,
                'spawn' => [$data->spawn->x, $data->spawn->y, $data->spawn->z],
                'time' => $data->time,
                'version' => 5,
                'weather' => [
                    'cycle' => $data->weatherCycleEnabled,
                    'remaining_ticks' => $data->weather->weather->remainingTicks,
                    'sequence' => $data->weather->transitionSequence,
                    'type' => $data->weather->weather->type->value,
                ],
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
            $value = json_decode($encoded, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('World data IPC value is malformed.', previous: $error);
        }
        if (!is_array($value) || array_keys($value) !== [
            'difficulty', 'dimensions', 'generator', 'generator_options', 'generator_version_declared', 'generator_version', 'name', 'seed', 'spawn', 'time', 'version', 'weather',
        ] || $value['version'] !== 5 || !is_int($value['difficulty']) || !is_array($value['dimensions'])
            || !is_string($value['generator']) || !is_int($value['generator_version'])
            || !is_bool($value['generator_version_declared'])
            || !is_string($value['generator_options'])
            || !is_string($value['name']) || !is_int($value['seed'])
            || !is_array($value['spawn']) || !array_is_list($value['spawn']) || count($value['spawn']) !== 3
            || !is_int($value['spawn'][0]) || !is_int($value['spawn'][1]) || !is_int($value['spawn'][2])
            || !is_int($value['time']) || !is_array($value['weather'])
            || array_keys($value['weather']) !== ['cycle', 'remaining_ticks', 'sequence', 'type']
            || !is_bool($value['weather']['cycle']) || !is_int($value['weather']['remaining_ticks'])
            || !is_int($value['weather']['sequence']) || !is_string($value['weather']['type'])) {
            throw new RuntimeException('World data IPC value has an invalid shape.');
        }

        $dimensions = [];
        foreach (DimensionType::cases() as $type) {
            $dimension = $value['dimensions'][$type->value] ?? null;
            if (!is_array($dimension) || array_keys($dimension) !== [
                'declared', 'generator', 'options', 'seed', 'spawn', 'version',
            ] || !is_bool($dimension['declared']) || !is_string($dimension['generator'])
                || !is_string($dimension['options']) || !is_int($dimension['seed'])
                || !is_array($dimension['spawn']) || !array_is_list($dimension['spawn'])
                || count($dimension['spawn']) !== 3 || !is_int($dimension['spawn'][0])
                || !is_int($dimension['spawn'][1]) || !is_int($dimension['spawn'][2])
                || !is_int($dimension['version'])) {
                throw new RuntimeException('World data IPC value contains invalid dimension metadata.');
            }
            $dimensions[$type->value] = new DimensionWorldData(
                $dimension['generator'],
                $dimension['seed'],
                new SpawnPosition(...$dimension['spawn']),
                $dimension['version'],
                $dimension['options'],
                $dimension['declared'],
            );
        }
        if (count($value['dimensions']) !== count($dimensions)) {
            throw new RuntimeException('World data IPC value contains unsupported dimension metadata.');
        }

        $weatherType = WeatherType::tryFrom($value['weather']['type']);
        if ($weatherType === null) {
            throw new RuntimeException('World data IPC value contains an unsupported weather type.');
        }

        return new WorldData(
            new WorldMetadata($value['name'], $value['seed']),
            $value['generator'],
            new SpawnPosition($value['spawn'][0], $value['spawn'][1], $value['spawn'][2]),
            $value['time'],
            $value['difficulty'],
            $value['generator_version'],
            $value['generator_options'],
            new WeatherCycleState(
                new WeatherState($weatherType, $value['weather']['remaining_ticks']),
                $value['weather']['sequence'],
            ),
            $value['weather']['cycle'],
            $value['generator_version_declared'],
            $dimensions,
        );
    }
}
