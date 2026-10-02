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
use Bedriox\Api\Plugin\Data\PluginDataException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class FileConfiguration implements Configuration
{
    public const int MAXIMUM_BYTES = 1_048_576;
    private const int MAXIMUM_DEPTH = 32;
    private const int MAXIMUM_NODES = 65_536;

    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct(
        private readonly string $path,
        private readonly string $format,
    ) {
        $this->reload();
    }

    public function has(string $key): bool
    {
        [$found] = $this->find($key);

        return $found;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->find($key);

        return $found ? $value : $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        if (!is_string($value)) {
            throw $this->typeFailure($key, 'string');
        }

        return $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        if (!is_int($value)) {
            throw $this->typeFailure($key, 'integer');
        }

        return $value;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);
        if (!is_float($value) && !is_int($value)) {
            throw $this->typeFailure($key, 'number');
        }
        $value = (float) $value;
        if (!is_finite($value)) {
            throw $this->typeFailure($key, 'finite number');
        }

        return $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (!is_bool($value)) {
            throw $this->typeFailure($key, 'boolean');
        }

        return $value;
    }

    public function getList(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->typeFailure($key, 'list');
        }

        return $value;
    }

    public function getMap(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw $this->typeFailure($key, 'map');
        }
        try {
            return self::stringMap($value);
        } catch (PluginDataException) {
            throw $this->typeFailure($key, 'string-keyed map');
        }
    }

    public function set(string $key, mixed $value): void
    {
        self::validateValue($value);
        $segments = $this->segments($key);
        $cursor = &$this->values;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            if (!is_array($cursor[$segment]) || array_is_list($cursor[$segment]) && $cursor[$segment] !== []) {
                throw new PluginDataException("Configuration key {$key} crosses a non-map value.");
            }
            $cursor = &$cursor[$segment];
        }
        $cursor[$last] = $value;
    }

    public function remove(string $key): void
    {
        $segments = $this->segments($key);
        $cursor = &$this->values;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                return;
            }
            $cursor = &$cursor[$segment];
        }
        unset($cursor[$last]);
    }

    public function all(): array
    {
        return $this->values;
    }

    public function save(): void
    {
        self::validateRoot($this->values);
        try {
            $contents = $this->format === 'json'
                ? json_encode($this->values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
                : Yaml::dump($this->values, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        } catch (\Throwable $failure) {
            throw new PluginDataException('Plugin configuration could not be encoded.', 0, $failure);
        }
        if (strlen($contents) > self::MAXIMUM_BYTES) {
            throw new PluginDataException('Plugin configuration exceeds the maximum encoded size.');
        }
        FilePluginData::writeFile($this->path, $contents, true);
    }

    public function reload(): void
    {
        $size = @filesize($this->path);
        if (!is_int($size) || $size > self::MAXIMUM_BYTES) {
            throw new PluginDataException('Plugin configuration size is invalid.');
        }
        $contents = @file_get_contents($this->path);
        if (!is_string($contents)) {
            throw new PluginDataException('Plugin configuration could not be read.');
        }
        try {
            $decoded = $this->format === 'json'
                ? json_decode($contents, true, 32, JSON_THROW_ON_ERROR)
                : Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (\JsonException|ParseException $failure) {
            throw new PluginDataException('Plugin configuration is malformed.', 0, $failure);
        }
        if ($decoded === null) {
            $decoded = [];
        }
        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new PluginDataException('Plugin configuration root must be a map.');
        }
        self::validateRoot($decoded);
        $this->values = self::stringMap($decoded);
    }

    /** @return array{bool, mixed} */
    private function find(string $key): array
    {
        $segments = $this->segments($key);
        $value = $this->values;
        foreach ($segments as $index => $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
            if ($index < count($segments) - 1 && !is_array($value)) {
                return [false, null];
            }
        }

        return [true, $value];
    }

    /** @return non-empty-list<string> */
    private function segments(string $key): array
    {
        if ($key === '' || strlen($key) > 512 || str_contains($key, "\0")) {
            throw new PluginDataException('Configuration key is invalid.');
        }
        $segments = explode('.', $key);
        foreach ($segments as $segment) {
            if ($segment === '' || strlen($segment) > 128) {
                throw new PluginDataException('Configuration key is invalid.');
            }
        }

        return $segments;
    }

    private function typeFailure(string $key, string $expected): PluginDataException
    {
        return new PluginDataException("Configuration key {$key} must contain a {$expected}.");
    }

    /** @param array<mixed, mixed> $values */
    private static function validateRoot(array $values): void
    {
        $nodes = 0;
        self::validateValue($values, 0, $nodes);
        foreach (array_keys($values) as $key) {
            if (!is_string($key)) {
                throw new PluginDataException('Plugin configuration root must use string keys.');
            }
        }
    }

    private static function validateValue(mixed $value, int $depth = 0, int &$nodes = 0): void
    {
        if (++$nodes > self::MAXIMUM_NODES || $depth > self::MAXIMUM_DEPTH) {
            throw new PluginDataException('Plugin configuration structure exceeds its bounds.');
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new PluginDataException('Plugin configuration numbers must be finite.');
            }
            return;
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new PluginDataException('Plugin configuration strings must be valid UTF-8.');
            }
            return;
        }
        if (!is_array($value)) {
            throw new PluginDataException('Plugin configuration contains an unsupported value type.');
        }
        $list = array_is_list($value);
        foreach ($value as $key => $entry) {
            if (!$list && (!is_string($key) || $key === '' || strlen($key) > 256)) {
                throw new PluginDataException('Plugin configuration maps must use bounded string keys.');
            }
            self::validateValue($entry, $depth + 1, $nodes);
        }
    }

    /** @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new PluginDataException('Plugin configuration map must use string keys.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
