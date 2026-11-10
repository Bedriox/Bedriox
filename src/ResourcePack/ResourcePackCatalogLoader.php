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
    private const int MAXIMUM_ZIP_ENTRIES = 32_768;
    private const int MAXIMUM_CENTRAL_DIRECTORY_BYTES = 67_108_864;
    private const int MAXIMUM_CACHE_ENTRIES = 65_536;

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
            if ($total > ResourcePackCatalog::MAXIMUM_TOTAL_BYTES) {
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
        [$temporary, $size, $hash] = $this->copyOwnedSnapshot($source, $cache);
        try {
            $this->validateZipDirectory($temporary, $size);
            $zip = new ZipArchive();
            $opened = $zip->open($temporary, ZipArchive::RDONLY);
            if ($opened !== true) {
                throw new ResourcePackException("Resource-pack archive could not be opened (ZipArchive {$opened}).");
            }
            try {
                if ($zip->numFiles < 1 || $zip->numFiles > self::MAXIMUM_ZIP_ENTRIES) {
                    throw new ResourcePackException('Resource-pack archive contains too many files.');
                }
                $manifestEntries = 0;
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    if ($zip->getNameIndex($index) === 'manifest.json') {
                        ++$manifestEntries;
                    }
                }
                if ($manifestEntries !== 1) {
                    throw new ResourcePackException('Resource-pack archive must contain exactly one root manifest.json.');
                }
                $stat = $zip->statName('manifest.json');
                $manifestSize = is_array($stat) ? $stat['size'] : null;
                if (!is_int($manifestSize) || $manifestSize < 2 || $manifestSize > self::MAXIMUM_MANIFEST_BYTES) {
                    throw new ResourcePackException('Resource-pack archive needs a bounded manifest.json at its root.');
                }
                $manifestJson = $zip->getFromName('manifest.json', self::MAXIMUM_MANIFEST_BYTES);
                if (!is_string($manifestJson) || strlen($manifestJson) !== $manifestSize) {
                    throw new ResourcePackException('Resource-pack manifest could not be read completely.');
                }
            } finally {
                $zip->close();
            }
            $manifest = $this->manifest($manifestJson);
            $header = $manifest['header'];
            $id = strtolower($header['uuid']);
            $version = implode('.', $header['version']);
            $contentKey = $this->contentKey($source . '.key');
            $dependencies = [];
            foreach ($manifest['dependencies'] ?? [] as $dependency) {
                $dependencies[strtolower($dependency['uuid'])] = implode('.', $dependency['version']);
            }
            $snapshot = $this->publishSnapshot($temporary, $cache, $hash, $size);
            $temporary = '';

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
        } finally {
            if ($temporary !== '' && is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function cleanCache(string $cacheDirectory, ResourcePackCatalog $catalog): ResourcePackCacheCleanup
    {
        $this->prepareDirectory($cacheDirectory);
        $live = [];
        foreach ($catalog->stack as $pack) {
            $live[bin2hex($pack->sha256) . '.mcpack'] = true;
        }
        $files = 0;
        $bytes = 0;
        $inspected = 0;
        $iterator = new \FilesystemIterator($cacheDirectory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (++$inspected > self::MAXIMUM_CACHE_ENTRIES) {
                throw new ResourcePackException('Resource-pack cache exceeds 65536 entries.');
            }
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $name = $entry->getFilename();
            if ($entry->isLink() || !$entry->isFile()) {
                continue;
            }
            $ownedSnapshot = preg_match('/\A[0-9a-f]{64}\.mcpack\z/D', $name) === 1;
            $ownedPartial = preg_match('/\A\.incoming-[0-9a-f]{32}\.mcpack\z/D', $name) === 1;
            if ((!$ownedSnapshot || isset($live[$name])) && !$ownedPartial) {
                continue;
            }
            $size = $entry->getSize();
            if (@unlink($entry->getPathname())) {
                ++$files;
                $bytes += max(0, $size);
            }
        }

        return new ResourcePackCacheCleanup($files, $bytes);
    }

    /** @return array{string, int, string} */
    private function copyOwnedSnapshot(string $source, string $cache): array
    {
        $declaredSize = filesize($source);
        if ($declaredSize === false || $declaredSize < 1 || $declaredSize > self::MAXIMUM_PACK_BYTES) {
            throw new ResourcePackException('Resource-pack archive must be between 1 byte and 512 MiB.');
        }
        $temporary = $cache . DIRECTORY_SEPARATOR . '.incoming-' . bin2hex(random_bytes(16)) . '.mcpack';
        $input = @fopen($source, 'rb');
        $output = @fopen($temporary, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
            throw new ResourcePackException('Resource-pack immutable snapshot could not be created.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1_048_576);
                if (!is_string($chunk)) {
                    throw new ResourcePackException('Resource-pack source could not be read completely.');
                }
                if ($chunk === '') {
                    if (!feof($input)) {
                        throw new ResourcePackException('Resource-pack source stopped before end of file.');
                    }
                    break;
                }
                $length = strlen($chunk);
                if ($length > self::MAXIMUM_PACK_BYTES - $size) {
                    throw new ResourcePackException('Resource-pack archive exceeds 512 MiB while being copied.');
                }
                $written = 0;
                while ($written < $length) {
                    $count = fwrite($output, substr($chunk, $written));
                    if (!is_int($count) || $count < 1) {
                        throw new ResourcePackException('Resource-pack immutable snapshot could not be written completely.');
                    }
                    $written += $count;
                }
                hash_update($hash, $chunk);
                $size += $length;
            }
            if ($size < 1 || !fflush($output)) {
                throw new ResourcePackException('Resource-pack immutable snapshot is empty or could not be flushed.');
            }
            if (function_exists('fsync') && !fsync($output)) {
                throw new ResourcePackException('Resource-pack immutable snapshot could not be synchronized.');
            }
        } catch (\Throwable $failure) {
            fclose($input);
            fclose($output);
            @unlink($temporary);
            throw $failure;
        }
        fclose($input);
        fclose($output);
        $digest = hash_final($hash, true);
        if (strlen($digest) !== 32 || filesize($temporary) !== $size) {
            @unlink($temporary);
            throw new ResourcePackException('Resource-pack immutable snapshot verification failed.');
        }

        return [$temporary, $size, $digest];
    }

    private function publishSnapshot(string $temporary, string $cache, string $hash, int $size): string
    {
        $snapshot = $cache . DIRECTORY_SEPARATOR . bin2hex($hash) . '.mcpack';
        if (is_file($snapshot) && !is_link($snapshot) && filesize($snapshot) === $size) {
            $existingHash = hash_file('sha256', $snapshot, true);
            if (is_string($existingHash) && hash_equals($hash, $existingHash)) {
                @unlink($temporary);

                return $snapshot;
            }
        }
        if (file_exists($snapshot)) {
            if (is_link($snapshot) || !is_file($snapshot) || !@unlink($snapshot)) {
                throw new ResourcePackException('Resource-pack cache contains an invalid snapshot target.');
            }
        }
        if (!@rename($temporary, $snapshot)) {
            throw new ResourcePackException('Resource-pack immutable snapshot could not be published.');
        }

        return $snapshot;
    }

    private function contentKey(string $keyPath): string
    {
        if (!file_exists($keyPath)) {
            return '';
        }
        if (!is_file($keyPath) || is_link($keyPath)) {
            throw new ResourcePackException('Resource-pack content key must be a regular file.');
        }
        $size = filesize($keyPath);
        if (!is_int($size) || !in_array($size, [32, 33, 34], true)) {
            throw new ResourcePackException('Resource-pack content key must contain exactly 32 bytes with an optional line ending.');
        }
        $key = file_get_contents($keyPath, false, null, 0, 35);
        if (!is_string($key) || strlen($key) !== $size) {
            throw new ResourcePackException('Resource-pack content key could not be read completely.');
        }
        if ($size === 33 && !str_ends_with($key, "\n")) {
            throw new ResourcePackException('Resource-pack content key has an invalid trailing byte.');
        }
        if ($size === 34 && !str_ends_with($key, "\r\n")) {
            throw new ResourcePackException('Resource-pack content key has an invalid trailing line ending.');
        }
        $contentKey = $size === 32 ? $key : substr($key, 0, 32);
        if (strlen($contentKey) !== 32 || str_contains($contentKey, "\0")) {
            throw new ResourcePackException('Resource-pack content key must contain exactly 32 non-NUL bytes.');
        }

        return $contentKey;
    }

    private function validateZipDirectory(string $archive, int $size): void
    {
        $tailBytes = max(1, min($size, 65_557));
        $handle = @fopen($archive, 'rb');
        if ($handle === false) {
            throw new ResourcePackException('Resource-pack snapshot could not be inspected.');
        }
        try {
            if (fseek($handle, $size - $tailBytes, SEEK_SET) !== 0) {
                throw new ResourcePackException('Resource-pack ZIP directory could not be positioned.');
            }
            $tail = fread($handle, $tailBytes);
        } finally {
            fclose($handle);
        }
        if (!is_string($tail) || strlen($tail) !== $tailBytes) {
            throw new ResourcePackException('Resource-pack ZIP directory could not be read completely.');
        }
        $relative = strrpos($tail, "PK\x05\x06");
        if ($relative === false || strlen($tail) - $relative < 22) {
            throw new ResourcePackException('Resource-pack ZIP end record is missing or truncated.');
        }
        $fields = unpack(
            'vdisk/vdirectory_disk/ventries_disk/ventries/Vdirectory_bytes/Vdirectory_offset/vcomment_bytes',
            substr($tail, $relative + 4, 18),
        );
        if (!is_array($fields)) {
            throw new ResourcePackException('Resource-pack ZIP end record is malformed.');
        }
        foreach (['disk', 'directory_disk', 'entries_disk', 'entries', 'directory_bytes', 'directory_offset', 'comment_bytes'] as $field) {
            if (!isset($fields[$field]) || !is_int($fields[$field])) {
                throw new ResourcePackException('Resource-pack ZIP end record contains an invalid field.');
            }
        }
        $absolute = $size - $tailBytes + $relative;
        if ($fields['disk'] !== 0 || $fields['directory_disk'] !== 0
            || $fields['entries_disk'] !== $fields['entries']
            || $fields['entries'] === 0xffff || $fields['directory_bytes'] === 0xffff_ffff
            || $fields['directory_offset'] === 0xffff_ffff
            || $fields['entries'] < 1 || $fields['entries'] > self::MAXIMUM_ZIP_ENTRIES
            || $fields['directory_bytes'] > self::MAXIMUM_CENTRAL_DIRECTORY_BYTES
            || $fields['directory_offset'] > $absolute
            || $fields['directory_bytes'] > $absolute - $fields['directory_offset']
            || $absolute + 22 + $fields['comment_bytes'] !== $size) {
            throw new ResourcePackException('Resource-pack ZIP directory exceeds its supported bounds.');
        }
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
