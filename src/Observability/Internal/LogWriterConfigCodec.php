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

namespace Bedriox\Server\Observability\Internal;

use JsonException;
use RuntimeException;

final class LogWriterConfigCodec
{
    private const int MAXIMUM_CONFIG_BYTES = 8_192;

    public function encode(string $path, int $maximumBytes, int $history): string
    {
        if ($path === '' || strlen($path) > 4_096 || str_contains($path, "\0") || $maximumBytes < 1 || $history < 0) {
            throw new \InvalidArgumentException('Background log writer configuration is invalid.');
        }
        try {
            $encoded = json_encode([
                'history' => $history,
                'maximum_bytes' => $maximumBytes,
                'path' => $path,
                'version' => 1,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new RuntimeException('Background log writer configuration could not be encoded.', previous: $error);
        }
        if (strlen($encoded) > self::MAXIMUM_CONFIG_BYTES) {
            throw new \InvalidArgumentException('Background log writer configuration exceeds its size limit.');
        }

        return $encoded;
    }

    /** @return array{path: string, maximum_bytes: int, history: int} */
    public function decode(string $payload): array
    {
        if (strlen($payload) > self::MAXIMUM_CONFIG_BYTES) {
            throw new RuntimeException('Background log writer configuration exceeds its size limit.');
        }
        try {
            $config = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Background log writer configuration is malformed.', previous: $error);
        }
        if (!is_array($config) || array_keys($config) !== ['history', 'maximum_bytes', 'path', 'version']
            || $config['version'] !== 1 || !is_string($config['path']) || $config['path'] === ''
            || strlen($config['path']) > 4_096 || str_contains($config['path'], "\0")
            || !is_int($config['maximum_bytes']) || $config['maximum_bytes'] < 1
            || !is_int($config['history']) || $config['history'] < 0) {
            throw new RuntimeException('Background log writer configuration has an invalid shape.');
        }

        return [
            'path' => $config['path'],
            'maximum_bytes' => $config['maximum_bytes'],
            'history' => $config['history'],
        ];
    }
}
