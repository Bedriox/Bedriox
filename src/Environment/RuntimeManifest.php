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

namespace Bedriox\Server\Environment;

use JsonException;

final readonly class RuntimeManifest
{
    private const int MAXIMUM_MANIFEST_BYTES = 1_048_576;
    private const int MAXIMUM_FILES = 4_096;
    private const int MAXIMUM_FILE_BYTES = 1_073_741_824;
    private const int MAXIMUM_TOTAL_BYTES = 2_147_483_648;

    /**
     * @param array<string, array{bytes: int, sha256: string}> $files
     */
    private function __construct(
        public string $sha256,
        public string $target,
        public string $architecture,
        public string $phpVersion,
        public bool $threadSafe,
        public array $files,
    ) {}

    public static function load(string $path): self
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeEnvironmentException('The packaged runtime manifest is missing or is a link.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAXIMUM_MANIFEST_BYTES) {
            throw new RuntimeEnvironmentException('The packaged runtime manifest has an invalid size.');
        }
        $json = file_get_contents($path);
        if (!is_string($json) || strlen($json) !== $size) {
            throw new RuntimeEnvironmentException('The packaged runtime manifest could not be read completely.');
        }
        $sha256 = hash('sha256', $json);
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeEnvironmentException('The packaged runtime manifest is not valid JSON.', previous: $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeEnvironmentException('The packaged runtime manifest root must be an object.');
        }
        $expectedKeys = ['architecture', 'buildPolicy', 'compiler', 'files', 'linker', 'phpVersion', 'schemaVersion', 'sources', 'target', 'threadSafe'];
        $actualKeys = array_keys($decoded);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys || ($decoded['schemaVersion'] ?? null) !== 2) {
            throw new RuntimeEnvironmentException('The packaged runtime manifest schema is unsupported.');
        }
        $target = $decoded['target'] ?? null;
        $architecture = $decoded['architecture'] ?? null;
        $phpVersion = $decoded['phpVersion'] ?? null;
        $threadSafe = $decoded['threadSafe'] ?? null;
        if (!is_string($target) || !in_array($target, [
            'windows-x86_64', 'windows-arm64', 'linux-x86_64', 'linux-arm64', 'macos-x86_64', 'macos-arm64',
        ], true)) {
            throw new RuntimeEnvironmentException('The packaged runtime target is invalid.');
        }
        if (!is_string($architecture) || $architecture === '' || strlen($architecture) > 64
            || !is_string($phpVersion) || preg_match('/^8\.4\.\d{1,3}$/D', $phpVersion) !== 1
            || !is_bool($threadSafe)) {
            throw new RuntimeEnvironmentException('The packaged runtime identity is invalid.');
        }
        $files = $decoded['files'] ?? null;
        if (!is_array($files) || array_is_list($files) || count($files) > self::MAXIMUM_FILES) {
            throw new RuntimeEnvironmentException('The packaged runtime file inventory is invalid.');
        }
        $declaredPaths = array_keys($files);
        $sortedPaths = $declaredPaths;
        sort($sortedPaths, SORT_STRING);
        if ($declaredPaths !== $sortedPaths) {
            throw new RuntimeEnvironmentException('The packaged runtime file inventory is not sorted.');
        }
        $validated = [];
        $caseFolded = [];
        $totalBytes = 0;
        foreach ($files as $relative => $metadata) {
            if (!is_string($relative) || !self::isSafeRelativePath($relative) || isset($caseFolded[strtolower($relative)])) {
                throw new RuntimeEnvironmentException('The packaged runtime file inventory contains an unsafe or duplicate path.');
            }
            $caseFolded[strtolower($relative)] = true;
            if (!is_array($metadata) || array_keys($metadata) !== ['bytes', 'sha256']) {
                throw new RuntimeEnvironmentException('The packaged runtime file metadata is invalid.');
            }
            $bytes = $metadata['bytes'] ?? null;
            $hash = $metadata['sha256'] ?? null;
            if (!is_int($bytes) || $bytes < 0 || $bytes > self::MAXIMUM_FILE_BYTES
                || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                throw new RuntimeEnvironmentException('The packaged runtime file identity is invalid.');
            }
            if ($totalBytes > self::MAXIMUM_TOTAL_BYTES - $bytes) {
                throw new RuntimeEnvironmentException('The packaged runtime file inventory exceeds its byte limit.');
            }
            $totalBytes += $bytes;
            $validated[$relative] = ['bytes' => $bytes, 'sha256' => $hash];
        }

        return new self($sha256, $target, $architecture, $phpVersion, $threadSafe, $validated);
    }

    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === 'runtime-manifest.json' || str_ends_with(strtolower($path), '.sha256')
            || strlen($path) > 240 || preg_match('#^[A-Za-z0-9._+/-]+$#D', $path) !== 1
            || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                return false;
            }
        }

        return true;
    }
}
