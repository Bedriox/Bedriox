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

use Bedriox\Api\Plugin\Data\Configuration;
use Bedriox\Api\Plugin\Data\PluginData;
use Bedriox\Api\Plugin\Data\PluginDataException;

final class FilePluginData implements PluginData
{
    private const int MAXIMUM_RESOURCE_NAME_BYTES = 512;

    /** @var array<string, Configuration> */
    private array $configurations = [];

    public function __construct(
        private readonly string $directory,
        private readonly PluginResourceProvider $resources,
    ) {}

    public function path(): string
    {
        return $this->directory;
    }

    public function hasResource(string $name): bool
    {
        return $this->resources->has($this->normalize($name));
    }

    public function readResource(string $name): string
    {
        return $this->resources->read($this->normalize($name));
    }

    public function saveResource(string $name, bool $replace = false): bool
    {
        $name = $this->normalize($name);
        if (!$this->resources->has($name)) {
            throw new PluginDataException("Plugin resource {$name} does not exist.");
        }
        $destination = $this->destination($name);
        if (file_exists($destination) && !$replace) {
            return false;
        }
        self::writeFile($destination, $this->resources->read($name), $replace);
        if (isset($this->configurations[$name])) {
            $this->configurations[$name]->reload();
        }

        return true;
    }

    public function saveResources(bool $replace = false): int
    {
        $saved = 0;
        foreach ($this->resources->names() as $name) {
            if ($this->saveResource($name, $replace)) {
                ++$saved;
            }
        }

        return $saved;
    }

    public function config(string $name = 'config.yml'): Configuration
    {
        $name = $this->normalize($name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $format = match ($extension) {
            'yml', 'yaml' => 'yaml',
            'json' => 'json',
            default => throw new PluginDataException('Plugin configuration must use .yml, .yaml, or .json.'),
        };
        if (isset($this->configurations[$name])) {
            return $this->configurations[$name];
        }
        $destination = $this->destination($name);
        if (!file_exists($destination)) {
            if ($this->resources->has($name)) {
                $this->saveResource($name);
            } else {
                self::writeFile($destination, $format === 'json' ? "{}\n" : "{}\n", false);
            }
        }

        return $this->configurations[$name] = new FileConfiguration($destination, $format);
    }

    public static function writeFile(string $path, string $contents, bool $replace): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new PluginDataException('Plugin data directory could not be created.');
        }
        if (is_link($directory) || is_link($path)) {
            throw new PluginDataException('Plugin data paths may not use symbolic links.');
        }
        if (!$replace && file_exists($path)) {
            throw new PluginDataException('Plugin data file already exists.');
        }
        $temporary = @tempnam($directory, '.bedriox-');
        if (!is_string($temporary)) {
            throw new PluginDataException('Plugin data staging file could not be created.');
        }
        $backup = null;
        try {
            $written = @file_put_contents($temporary, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                throw new PluginDataException('Plugin data file could not be written completely.');
            }
            if ($replace && file_exists($path)) {
                $backup = @tempnam($directory, '.bedriox-backup-');
                if (!is_string($backup) || !@unlink($backup) || !@rename($path, $backup)) {
                    throw new PluginDataException('Existing plugin data file could not be staged for replacement.');
                }
            }
            if (!@rename($temporary, $path)) {
                if (is_string($backup) && !@rename($backup, $path)) {
                    throw new PluginDataException('Plugin data file could not be installed; the previous file remains in the plugin data directory.');
                }
                throw new PluginDataException('Plugin data file could not be installed.');
            }
            if (is_string($backup)) {
                @unlink($backup);
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
            if (is_string($backup) && file_exists($backup) && file_exists($path)) {
                @unlink($backup);
            }
        }
    }

    private function destination(string $name): string
    {
        $segments = explode('/', $name);
        $cursor = $this->directory;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (file_exists($cursor) && (!is_dir($cursor) || is_link($cursor))) {
                throw new PluginDataException('Plugin data path contains an unsafe directory.');
            }
        }

        return $this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
    }

    private function normalize(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || strlen($name) > self::MAXIMUM_RESOURCE_NAME_BYTES || str_contains($name, "\0")
            || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) === 1) {
            throw new PluginDataException('Plugin data path is invalid.');
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new PluginDataException('Plugin data path is invalid.');
            }
        }

        return $name;
    }
}
