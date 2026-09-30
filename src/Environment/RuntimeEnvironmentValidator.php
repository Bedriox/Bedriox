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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RuntimeEnvironmentValidator
{
    private const int MAXIMUM_ACTUAL_FILES = 4_098;

    public static function validate(
        string $projectRoot,
        string|false $configuredRuntimeRoot,
        ?RuntimeProcessIdentity $process = null,
        ?string $lockFile = null,
    ): RuntimeManifest {
        $process ??= RuntimeProcessIdentity::current();
        $project = realpath($projectRoot);
        if (!is_string($project) || !is_dir($project)) {
            throw new RuntimeEnvironmentException('The Bedriox installation root is unavailable.');
        }
        $runtimePath = $project . DIRECTORY_SEPARATOR . 'bin';
        $runtime = realpath($runtimePath);
        if (!is_string($runtime) || !is_dir($runtime) || is_link($runtimePath)) {
            throw new RuntimeEnvironmentException('The adjacent packaged runtime directory is unavailable.');
        }
        if (!is_string($configuredRuntimeRoot) || !self::samePath($runtime, $configuredRuntimeRoot)) {
            throw new RuntimeEnvironmentException('BEDRIOX_RUNTIME_ROOT must identify the adjacent bin directory.');
        }
        $expectedExecutable = $runtime . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (is_link($expectedExecutable) || !is_file($expectedExecutable) || !self::samePath($expectedExecutable, $process->binary)) {
            throw new RuntimeEnvironmentException('Bedriox must run with the adjacent packaged PHP executable.');
        }
        $expectedIni = $runtime . DIRECTORY_SEPARATOR . 'php.ini';
        if (is_link($expectedIni) || !is_file($expectedIni) || !is_string($process->loadedIni)
            || !self::samePath($expectedIni, $process->loadedIni)) {
            throw new RuntimeEnvironmentException('Bedriox must run with the adjacent packaged php.ini.');
        }
        if (is_string($process->scannedIni) && trim($process->scannedIni) !== '') {
            throw new RuntimeEnvironmentException('Additional scanned PHP configuration files are not permitted.');
        }

        $manifest = RuntimeManifest::load($runtime . DIRECTORY_SEPARATOR . 'runtime-manifest.json');
        $expected = RuntimeArtifactExpectation::fromLockFile(
            $lockFile ?? $project . DIRECTORY_SEPARATOR . 'bedriox.lock.json',
            $manifest->target,
        );
        if ($expected->manifestSchema !== 2 || !hash_equals($expected->manifestSha256, $manifest->sha256)) {
            throw new RuntimeEnvironmentException('The packaged runtime manifest does not match the trusted integration lock.');
        }
        if ($manifest->phpVersion !== $expected->phpVersion || $manifest->threadSafe !== $expected->threadSafe) {
            throw new RuntimeEnvironmentException('The packaged runtime identity does not match the trusted integration lock.');
        }
        self::validateProcessIdentity($manifest, $process);
        self::validateExtensions($process->extensions, $expected->extensions);
        self::validateInventory($runtime, $manifest);

        return $manifest;
    }

    private static function validateProcessIdentity(RuntimeManifest $manifest, RuntimeProcessIdentity $process): void
    {
        if ($manifest->phpVersion !== $process->version || $manifest->threadSafe !== $process->threadSafe
            || $process->sapi !== 'cli' || $process->integerSize !== 8) {
            throw new RuntimeEnvironmentException('The running PHP identity differs from the packaged runtime manifest.');
        }
        $expectedFamily = str_starts_with($manifest->target, 'windows-')
            ? 'Windows'
            : (str_starts_with($manifest->target, 'macos-') ? 'Darwin' : 'Linux');
        if ($process->osFamily !== $expectedFamily) {
            throw new RuntimeEnvironmentException('The running operating system differs from the packaged runtime target.');
        }
        $machine = strtolower($process->machine);
        $architectureMatches = str_ends_with($manifest->target, '-x86_64')
            ? in_array($machine, ['amd64', 'x86_64'], true)
            : in_array($machine, ['aarch64', 'arm64'], true);
        if (!$architectureMatches) {
            throw new RuntimeEnvironmentException('The running architecture differs from the packaged runtime target.');
        }
        $manifestMachine = strtolower($manifest->architecture);
        $manifestArchitectureMatches = str_ends_with($manifest->target, '-x86_64')
            ? in_array($manifestMachine, ['amd64', 'x86_64'], true)
            : in_array($manifestMachine, ['aarch64', 'arm64'], true);
        if (!$manifestArchitectureMatches) {
            throw new RuntimeEnvironmentException('The packaged runtime architecture differs from its target.');
        }
    }

    /**
     * @param list<string> $actual
     * @param list<string> $expected
     */
    private static function validateExtensions(array $actual, array $expected): void
    {
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeEnvironmentException('The loaded PHP extension set differs from the trusted runtime policy.');
        }
    }

    private static function validateInventory(string $runtime, RuntimeManifest $manifest): void
    {
        $actual = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                throw new RuntimeEnvironmentException('The packaged runtime contains an unreadable filesystem entry.');
            }
            if (count($actual) >= self::MAXIMUM_ACTUAL_FILES || $entry->isLink() || !$entry->isFile()) {
                throw new RuntimeEnvironmentException('The packaged runtime contains an unsupported filesystem entry or too many files.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($runtime) + 1));
            if (in_array($relative, ['runtime-manifest.json', 'bedriox'], true)) {
                continue;
            }
            $bytes = $entry->getSize();
            $hash = hash_file('sha256', $entry->getPathname());
            if (!is_string($hash)) {
                throw new RuntimeEnvironmentException('A packaged runtime file could not be hashed.');
            }
            $actual[$relative] = ['bytes' => $bytes, 'sha256' => $hash];
        }
        ksort($actual, SORT_STRING);
        if ($manifest->files !== $actual) {
            throw new RuntimeEnvironmentException('The packaged runtime file inventory does not match its manifest.');
        }
    }

    private static function samePath(string $expected, string $actual): bool
    {
        $expectedReal = realpath($expected);
        $actualReal = realpath($actual);
        if (!is_string($expectedReal) || !is_string($actualReal)) {
            return false;
        }

        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($expectedReal, $actualReal) === 0
            : $expectedReal === $actualReal;
    }
}
