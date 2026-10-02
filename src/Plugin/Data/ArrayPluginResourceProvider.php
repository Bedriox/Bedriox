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

namespace Bedriox\Server\Plugin\Data;

use Bedriox\Api\Plugin\Data\PluginDataException;

final readonly class ArrayPluginResourceProvider implements PluginResourceProvider
{
    private const int MAXIMUM_FILES = 2_048;
    private const int MAXIMUM_FILE_BYTES = 8_388_608;
    private const int MAXIMUM_TOTAL_BYTES = 67_108_864;

    /** @var array<string, string> */
    private array $resources;

    /** @param array<mixed, mixed> $resources */
    public function __construct(array $resources)
    {
        if (count($resources) > self::MAXIMUM_FILES) {
            throw new PluginDataException('Plugin resource count exceeds its limit.');
        }
        $total = 0;
        $validated = [];
        foreach ($resources as $name => $contents) {
            if (!is_string($name) || !is_string($contents) || $name === '' || strlen($name) > 512
                || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
                throw new PluginDataException('Plugin resource catalog contains an invalid entry.');
            }
            foreach (explode('/', $name) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new PluginDataException('Plugin resource catalog contains an unsafe path.');
                }
            }
            $bytes = strlen($contents);
            $total += $bytes;
            if ($bytes > self::MAXIMUM_FILE_BYTES || $total > self::MAXIMUM_TOTAL_BYTES) {
                throw new PluginDataException('Plugin resource catalog exceeds its size limits.');
            }
            $validated[$name] = $contents;
        }
        $this->resources = $validated;
    }

    public function names(): array
    {
        $names = array_keys($this->resources);
        sort($names, SORT_STRING);

        return $names;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->resources);
    }

    public function read(string $name): string
    {
        return $this->resources[$name]
            ?? throw new PluginDataException("Plugin resource {$name} does not exist.");
    }
}
