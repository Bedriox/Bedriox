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

namespace Bedriox\Server\ResourcePack;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

final readonly class ResourcePackCatalogLoader
{
    private const int MAXIMUM_CONFIG_BYTES = 65_536;
    private const int MAXIMUM_MANIFEST_BYTES = 262_144;
    private const int MAXIMUM_PACK_BYTES = 536_870_912;
    private const int MAXIMUM_TOTAL_BYTES = 2_147_483_648;

    /** @internal Loads one bounded archive into an immutable server-owned snapshot. */
    public function loadFile(string $source, string $subPack, string $cacheDirectory): ResourcePackArchive
    {
        $resolved = realpath($source);
        if ($resolved === false || !is_file($resolved) || is_link($source)) {
            throw new ResourcePackException('Resource-pack archive is unavailable or is not a regular file.');
        }
        if (!in_array(strtolower(pathinfo($resolved, PATHINFO_EXTENSION)), ['zip', 'mcpack'], true)) {
            throw new ResourcePackException('Resource packs must use .zip or .mcpack archives.');
        }
        if (strlen($subPack) > 128 || str_contains($subPack, "\0")) {
            throw new ResourcePackException('Resource-pack subpack name is invalid.');
        }
        $this->prepareDirectory($cacheDirectory);

        return $this->loadArchive($resolved, $subPack, $cacheDirectory);
    }

    public function load(string $directory): ResourcePackCatalog
    {
        $this->prepareDirectory($directory);
        $configurationPath = $directory . DIRECTORY_SEPARATOR . 'resource_packs.yml';
        if (!is_file($configurationPath)) {
            $this->createConfiguration($configurationPath);
        }
        $size = filesize($configurationPath);
        if ($size === false || $size > self::MAXIMUM_CONFIG_BYTES) {
            throw new ResourcePackException('resource_packs.yml exceeds 65536 bytes.');
        }
        try {
            $configuration = Yaml::parseFile($configurationPath, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $failure) {
            throw new ResourcePackException('resource_packs.yml is invalid: ' . $failure->getMessage(), 0, $failure);
        }
        if (!is_array($configuration) || array_is_list($configuration)) {
            throw new ResourcePackException('resource_packs.yml must contain a mapping.');
        }
        $unknown = array_diff_key($configuration, ['required' => true, 'stack' => true]);
        if ($unknown !== []) {
            throw new ResourcePackException('Unknown resource-pack option: ' . array_key_first($unknown));
        }
        $required = $configuration['required'] ?? false;
        $entries = $configuration['stack'] ?? [];
        if (!is_bool($required) || !is_array($entries) || !array_is_list($entries) || count($entries) > 32) {
            throw new ResourcePackException('Resource-pack required/stack configuration is invalid.');
        }

        $cache = $directory . DIRECTORY_SEPARATOR . '.cache';
        $this->prepareDirectory($cache);
        /** @var list<ResourcePackArchive> $packs */
        $packs = [];
        $total = 0;
        foreach ($entries as $offset => $entry) {
            [$source, $subPack] = $this->entry($entry, $offset);
            $path = $this->sourcePath($directory, $source);
            $pack = $this->loadArchive($path, $subPack, $cache);
            $total += $pack->size;
            if ($total > self::MAXIMUM_TOTAL_BYTES) {
                throw new ResourcePackException('Configured resource packs exceed the 2 GiB total limit.');
            }
            $packs[] = $pack;
        }

        return new ResourcePackCatalog($this->orderDependencies($packs), $required && $packs !== []);
    }

    /** @return array{string, string} */
    private function entry(mixed $entry, int $offset): array
    {
        if (is_string($entry)) {
            return [$entry, ''];
        }
        if (!is_array($entry) || array_is_list($entry)) {
            throw new ResourcePackException("Resource-pack stack entry {$offset} must be a filename or mapping.");
        }
        $unknown = array_diff_key($entry, ['source' => true, 'subpack' => true]);
        $source = $entry['source'] ?? null;
        $subPack = $entry['subpack'] ?? '';
        if ($unknown !== [] || !is_string($source) || !is_string($subPack) || $source === ''
            || strlen($source) > 512 || strlen($subPack) > 128 || str_contains($source, "\0")) {
            throw new ResourcePackException("Resource-pack stack entry {$offset} is invalid.");
        }

        return [$source, $subPack];
    }

    private function sourcePath(string $directory, string $source): string
    {
        if (preg_match('~\A(?:[A-Za-z]:[\\/]|[\\/])~', $source) === 1 || str_contains(str_replace('\\', '/', $source), '../')) {
            throw new ResourcePackException('Resource-pack sources must be relative filenames inside resource_packs/.');
        }
        $candidate = $directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $source);
        $resolved = realpath($candidate);
        $root = realpath($directory);
        if ($resolved === false || $root === false || !is_file($resolved) || is_link($candidate)
            || !str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower(str_replace('\\', '/', $root)) . '/')) {
            throw new ResourcePackException("Resource-pack source {$source} is unavailable or outside resource_packs/.");
        }
        $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        if (!in_array($extension, ['zip', 'mcpack'], true)) {
            throw new ResourcePackException('Resource packs must use .zip or .mcpack archives.');
        }

        return $resolved;
    }

    private function loadArchive(string $source, string $subPack, string $cache): ResourcePackArchive
    {
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > self::MAXIMUM_PACK_BYTES) {
            throw new ResourcePackException('Resource-pack archive must be between 1 byte and 512 MiB.');
        }
        $zip = new ZipArchive();
        $opened = $zip->open($source, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new ResourcePackException("Resource-pack archive could not be opened (ZipArchive {$opened}).");
        }
        try {
            $stat = $zip->statName('manifest.json');
            if (!is_array($stat) || $stat['size'] < 2 || $stat['size'] > self::MAXIMUM_MANIFEST_BYTES) {
                throw new ResourcePackException('Resource-pack archive needs a bounded manifest.json at its root.');
            }
            $manifestJson = $zip->getFromName('manifest.json', self::MAXIMUM_MANIFEST_BYTES);
            if (!is_string($manifestJson)) {
                throw new ResourcePackException('Resource-pack manifest could not be read.');
            }
        } finally {
            $zip->close();
        }
        $manifest = $this->manifest($manifestJson);
        $header = $manifest['header'];
        $id = strtolower($header['uuid']);
        $version = implode('.', $header['version']);
        $hash = hash_file('sha256', $source, true);
        if (!is_string($hash) || strlen($hash) !== 32) {
            throw new ResourcePackException('Resource-pack SHA-256 could not be calculated.');
        }
        $snapshot = $cache . DIRECTORY_SEPARATOR . bin2hex($hash) . '.mcpack';
        if (!is_file($snapshot) || filesize($snapshot) !== $size) {
            $temporary = $snapshot . '.partial-' . bin2hex(random_bytes(8));
            if (!copy($source, $temporary) || !rename($temporary, $snapshot)) {
                @unlink($temporary);
                throw new ResourcePackException('Resource-pack immutable snapshot could not be published.');
            }
        }
        $keyPath = $source . '.key';
        $contentKey = '';
        if (file_exists($keyPath)) {
            if (!is_file($keyPath) || is_link($keyPath)) {
                throw new ResourcePackException('Resource-pack content key must be a regular file.');
            }
            $key = file_get_contents($keyPath, false, null, 0, 35);
            if (!is_string($key) || strlen(rtrim($key, "\r\n")) !== 32) {
                throw new ResourcePackException('Resource-pack content key must contain exactly 32 bytes.');
            }
            $contentKey = rtrim($key, "\r\n");
        }
        $dependencies = [];
        foreach ($manifest['dependencies'] ?? [] as $dependency) {
            $dependencies[strtolower($dependency['uuid'])] = implode('.', $dependency['version']);
        }

        return new ResourcePackArchive(
            $id,
            $version,
            $header['name'],
            $size,
            $hash,
            $contentKey,
            $subPack,
            $snapshot,
            dependencies: $dependencies,
        );
    }

    /** @return array{header: array{uuid: string, version: list<int>, name: string}, dependencies?: list<array{uuid: string, version: list<int>}>} */
    private function manifest(string $json): array
    {
        try {
            $manifest = json_decode($this->stripComments($json), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new ResourcePackException('Resource-pack manifest is invalid JSON.', 0, $failure);
        }
        if (!is_array($manifest) || array_is_list($manifest) || !is_array($manifest['header'] ?? null)
            || !is_array($manifest['modules'] ?? null) || !array_is_list($manifest['modules']) || $manifest['modules'] === []) {
            throw new ResourcePackException('Resource-pack manifest is missing its header or modules.');
        }
        $header = $manifest['header'];
        $headerVersion = $this->version($header['version'] ?? null);
        if (!is_string($header['uuid'] ?? null) || !$this->uuid($header['uuid'])
            || !is_string($header['name'] ?? null) || $header['name'] === '' || strlen($header['name']) > 256
            || $headerVersion === null) {
            throw new ResourcePackException('Resource-pack manifest header is invalid.');
        }
        foreach ($manifest['modules'] as $module) {
            if (!is_array($module) || ($module['type'] ?? null) !== 'resources'
                || !is_string($module['uuid'] ?? null) || !$this->uuid($module['uuid'])
                || $this->version($module['version'] ?? null) === null) {
                throw new ResourcePackException('Only valid resource modules are supported.');
            }
        }
        $dependencies = $manifest['dependencies'] ?? [];
        if (!is_array($dependencies) || !array_is_list($dependencies) || count($dependencies) > 32) {
            throw new ResourcePackException('Resource-pack dependency list is invalid.');
        }
        $validatedDependencies = [];
        foreach ($dependencies as $dependency) {
            $dependencyVersion = is_array($dependency) ? $this->version($dependency['version'] ?? null) : null;
            if (!is_array($dependency) || !is_string($dependency['uuid'] ?? null)
                || !$this->uuid($dependency['uuid']) || $dependencyVersion === null) {
                throw new ResourcePackException('Resource-pack dependency is invalid.');
            }
            $validatedDependencies[] = [
                'uuid' => $dependency['uuid'],
                'version' => $dependencyVersion,
            ];
        }

        return [
            'header' => [
                'uuid' => $header['uuid'],
                'version' => $headerVersion,
                'name' => $header['name'],
            ],
            'dependencies' => $validatedDependencies,
        ];
    }

    private function stripComments(string $json): string
    {
        $output = '';
        $length = strlen($json);
        $quoted = false;
        $escaped = false;
        for ($index = 0; $index < $length; ++$index) {
            $character = $json[$index];
            if ($quoted) {
                $output .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }
                continue;
            }
            if ($character === '"') {
                $quoted = true;
                $output .= $character;
                continue;
            }
            if ($character === '/' && ($json[$index + 1] ?? '') === '/') {
                $index += 2;
                while ($index < $length && $json[$index] !== "\n") {
                    ++$index;
                }
                $output .= "\n";
                continue;
            }
            if ($character === '/' && ($json[$index + 1] ?? '') === '*') {
                $index += 2;
                while ($index + 1 < $length && !($json[$index] === '*' && $json[$index + 1] === '/')) {
                    $output .= $json[$index] === "\n" ? "\n" : ' ';
                    ++$index;
                }
                ++$index;
                continue;
            }
            $output .= $character;
        }

        return $output;
    }

    private function uuid(string $value): bool
    {
        return preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}\z/D', $value) === 1;
    }

    /** @return list<int>|null */
    private function version(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 3) {
            return null;
        }
        $validated = [];
        foreach ($value as $part) {
            if (!is_int($part) || $part < 0 || $part > 65_535) {
                return null;
            }
            $validated[] = $part;
        }

        return $validated;
    }

    /**
     * @param list<ResourcePackArchive> $packs
     * @return list<ResourcePackArchive>
     */
    private function orderDependencies(array $packs): array
    {
        $byId = [];
        foreach ($packs as $pack) {
            if (isset($byId[$pack->id])) {
                throw new ResourcePackException("Resource-pack UUID {$pack->id} is duplicated.");
            }
            $byId[$pack->id] = $pack;
        }
        foreach ($packs as $pack) {
            foreach ($pack->dependencies as $id => $version) {
                if (!isset($byId[$id]) || $byId[$id]->version !== $version) {
                    throw new ResourcePackException("Resource pack {$pack->name} requires unavailable {$id} version {$version}.");
                }
            }
        }
        $ordered = [];
        $visiting = [];
        $visited = [];
        $visit = function (ResourcePackArchive $pack) use (&$visit, &$ordered, &$visiting, &$visited, $byId): void {
            if (isset($visited[$pack->id])) {
                return;
            }
            if (isset($visiting[$pack->id])) {
                throw new ResourcePackException('Resource-pack dependencies contain a cycle.');
            }
            $visiting[$pack->id] = true;
            foreach (array_keys($pack->dependencies) as $dependency) {
                $visit($byId[$dependency]);
            }
            unset($visiting[$pack->id]);
            $visited[$pack->id] = true;
            $ordered[] = $pack;
        };
        foreach ($packs as $pack) {
            $visit($pack);
        }

        return $ordered;
    }

    private function prepareDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new ResourcePackException("Unable to create {$directory}.");
        }
        if (is_link($directory)) {
            throw new ResourcePackException('Resource-pack directories cannot be symbolic links.');
        }
    }

    private function createConfiguration(string $path): void
    {
        $contents = <<<'YAML'
# Bedriox resource packs. Packs are applied in this order; later entries override earlier entries.
# Set required to true to require players to accept the configured stack before joining.
required: false
stack: []
# Example:
# stack:
#   - source: Bedriox-Test.mcpack
#     subpack: ""
YAML;
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (is_file($path)) {
                return;
            }
            throw new ResourcePackException('Unable to create resource_packs.yml.');
        }
        try {
            if (fwrite($handle, $contents . PHP_EOL) !== strlen($contents . PHP_EOL) || !fflush($handle)) {
                throw new ResourcePackException('Unable to write resource_packs.yml.');
            }
        } finally {
            fclose($handle);
        }
    }
}
