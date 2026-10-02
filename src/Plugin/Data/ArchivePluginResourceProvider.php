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

final readonly class ArchivePluginResourceProvider implements PluginResourceProvider
{
    /** @param array<string, string> $paths */
    public function __construct(private array $paths) {}

    public function names(): array
    {
        $names = array_keys($this->paths);
        sort($names, SORT_STRING);

        return $names;
    }

    public function has(string $name): bool
    {
        return isset($this->paths[$name]);
    }

    public function read(string $name): string
    {
        $path = $this->paths[$name] ?? null;
        if ($path === null) {
            throw new PluginDataException("Plugin resource {$name} does not exist.");
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new PluginDataException("Plugin resource {$name} could not be read.");
        }

        return $contents;
    }
}
