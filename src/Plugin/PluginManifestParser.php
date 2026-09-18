<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use JsonException;

final class PluginManifestParser
{
    private const int MAX_BYTES = 32768;
    private const int MAX_LIST_ENTRIES = 64;
    private const array FIELDS = [
        'schema', 'name', 'version', 'api', 'main', 'namespace', 'authors',
        'dependencies', 'softDependencies', 'load',
    ];

    public function parseFile(string $path): PluginManifest
    {
        if (!is_file($path) || is_link($path)) {
            throw new PluginException('Plugin manifest must be a regular file.');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new PluginException('Plugin manifest exceeds the size limit.');
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new PluginException('Plugin manifest could not be read.');
        }

        return $this->parse($json);
    }

    public function parse(string $json): PluginManifest
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new PluginException('Plugin manifest exceeds the size limit.');
        }
        $this->rejectDuplicateObjectKeys($json);
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PluginException('Plugin manifest is not valid JSON.', 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new PluginException('Plugin manifest must contain a JSON object.');
        }
        $unknown = array_diff(array_keys($data), self::FIELDS);
        if ($unknown !== []) {
            throw new PluginException('Unknown plugin manifest field: ' . (string) reset($unknown));
        }
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw new PluginException("Missing plugin manifest field: {$field}");
            }
        }
        if ($data['schema'] !== 1) {
            throw new PluginException('Unsupported plugin manifest schema.');
        }
        $name = $this->boundedString($data['name'], 'name', 1, 64, '/^[A-Z][A-Za-z0-9_]*$/D');
        $version = $this->boundedString($data['version'], 'version', 1, 64, '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/D');
        $api = $this->boundedString($data['api'], 'api', 1, 64, '/^[~^]?(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/D');
        $main = $this->className($data['main'], 'main');
        $namespace = $this->className($data['namespace'], 'namespace');
        if ($main !== $namespace && !str_starts_with($main, $namespace . '\\')) {
            throw new PluginException('Plugin entry point must be owned by its declared namespace.');
        }
        $authors = $this->stringList($data['authors'], 'authors', '/^[^\x00-\x1f\x7f]{1,80}$/D');
        $dependencies = $this->stringList($data['dependencies'], 'dependencies', '/^[A-Z][A-Za-z0-9_]{0,63}$/D');
        $softDependencies = $this->stringList($data['softDependencies'], 'softDependencies', '/^[A-Z][A-Za-z0-9_]{0,63}$/D');
        if (in_array($name, [...$dependencies, ...$softDependencies], true)) {
            throw new PluginException('A plugin may not depend on itself.');
        }
        if (array_intersect($dependencies, $softDependencies) !== []) {
            throw new PluginException('A dependency cannot be both required and soft.');
        }
        $load = $this->boundedString($data['load'], 'load', 1, 32, '/^(?:STARTUP|WORLD_READY)$/D');

        return new PluginManifest(1, $name, $version, $api, $main, $namespace, $authors, $dependencies, $softDependencies, $load);
    }

    private function boundedString(mixed $value, string $field, int $min, int $max, string $pattern): string
    {
        if (!is_string($value) || strlen($value) < $min || strlen($value) > $max || preg_match($pattern, $value) !== 1) {
            throw new PluginException("Invalid plugin manifest field: {$field}");
        }

        return $value;
    }

    private function className(mixed $value, string $field): string
    {
        return $this->boundedString($value, $field, 1, 255, '/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/D');
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field, string $pattern): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_LIST_ENTRIES) {
            throw new PluginException("Invalid plugin manifest field: {$field}");
        }
        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || preg_match($pattern, $entry) !== 1 || isset($result[strtolower($entry)])) {
                throw new PluginException("Invalid or duplicate entry in plugin manifest field: {$field}");
            }
            $result[strtolower($entry)] = $entry;
        }

        return array_values($result);
    }

    private function rejectDuplicateObjectKeys(string $json): void
    {
        $length = strlen($json);
        /** @var list<array{type: 'object'|'array', keys: array<string, true>}> $stack */
        $stack = [];
        $expectKey = false;
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if ($char === '"') {
                $start = $i;
                do {
                    ++$i;
                    if ($i >= $length) {
                        return;
                    }
                    if ($json[$i] === '\\') {
                        ++$i;
                    }
                } while ($i < $length && $json[$i] !== '"');
                if ($expectKey) {
                    $encoded = substr($json, $start, $i - $start + 1);
                    try {
                        $key = json_decode($encoded, true, 2, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        return;
                    }
                    $cursor = $i + 1;
                    while ($cursor < $length && ctype_space($json[$cursor])) {
                        ++$cursor;
                    }
                    if ($cursor < $length && $json[$cursor] === ':' && is_string($key)) {
                        $top = count($stack) - 1;
                        if (isset($stack[$top]['keys'][$key])) {
                            throw new PluginException("Duplicate JSON object key: {$key}");
                        }
                        $frame = $stack[$top];
                        $frame['keys'][$key] = true;
                        $stack[$top] = $frame;
                        $expectKey = false;
                    }
                }
                continue;
            }
            if ($char === '{') {
                $stack[] = ['type' => 'object', 'keys' => []];
                $expectKey = true;
            } elseif ($char === '[') {
                $stack[] = ['type' => 'array', 'keys' => []];
                $expectKey = false;
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
                $expectKey = false;
            } elseif ($char === ',' && $stack !== []) {
                $expectKey = $stack[array_key_last($stack)]['type'] === 'object';
            }
        }
    }
}
