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

use Bedriox\Server\Bedriox;

require dirname(__DIR__) . '/vendor/autoload.php';

const MAXIMUM_ARCHIVE_FILES = 8_192;
const MAXIMUM_ARCHIVE_BYTES = 536_870_912;

$root = dirname(__DIR__);
$workspace = dirname($root);
$output = $argv[1] ?? $root . '/build/Bedriox.phar';
$output = absolutePath($output, $root);
$outputDirectory = dirname($output);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create the PHAR output directory.');
}
if (is_link($outputDirectory)) {
    throw new RuntimeException('The PHAR output directory cannot be a symbolic link.');
}
if ((int) ini_get('phar.readonly') !== 0) {
    throw new RuntimeException('PHAR creation requires phar.readonly=0 for the build process.');
}

$stage = $workspace . DIRECTORY_SEPARATOR . '.bedriox-phar-stage-' . bin2hex(random_bytes(8));
if (!mkdir($stage, 0777, false)) {
    throw new RuntimeException('Unable to create the PHAR staging directory.');
}

try {
    foreach (['src', 'bootstrap'] as $directory) {
        copyTree($root . DIRECTORY_SEPARATOR . $directory, $stage . DIRECTORY_SEPARATOR . $directory);
    }
    foreach (['composer.json', 'composer.lock', 'bedriox.lock.json', 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md'] as $file) {
        copyFile($root . DIRECTORY_SEPARATOR . $file, $stage . DIRECTORY_SEPARATOR . $file);
    }
    installProductionDependencies($stage);
    pruneDevelopmentPackageFiles($stage);

    $files = inventory($stage);
    $manifest = json_encode([
        'schema' => 1,
        'version' => Bedriox::VERSION,
        'payload_hash' => payloadHash($files),
        'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    $temporary = $output . '.tmp.phar';
    @unlink($temporary);
    $phar = new Phar($temporary, 0, 'bedriox.phar');
    $phar->startBuffering();
    foreach (array_keys($files) as $relative) {
        $phar->addFile($stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), 'application/' . $relative);
    }
    $phar->addFromString('application-manifest.json', $manifest);
    $stub = file_get_contents(__DIR__ . '/phar-stub.php');
    if (!is_string($stub)) {
        throw new RuntimeException('Unable to read the PHAR startup stub.');
    }
    $phar->setStub(str_replace('@BEDRIOX_VERSION@', Bedriox::VERSION, $stub));
    $phar->setSignatureAlgorithm(Phar::SHA256);
    $phar->stopBuffering();
    unset($phar);

    if (is_file($output) && !unlink($output)) {
        throw new RuntimeException('Unable to replace the previous PHAR artifact.');
    }
    if (!rename($temporary, $output)) {
        throw new RuntimeException('Unable to publish the PHAR artifact.');
    }
    $sha256 = hash_file('sha256', $output);
    if (!is_string($sha256)) {
        throw new RuntimeException('Unable to hash the PHAR artifact.');
    }
    $checksumPath = $output . '.sha256';
    $checksum = $sha256 . '  ' . basename($output) . "\n";
    if (file_put_contents($checksumPath, $checksum, LOCK_EX) !== strlen($checksum)) {
        throw new RuntimeException('Unable to write the PHAR checksum.');
    }
    writeLaunchers($outputDirectory, basename($output));
    fwrite(STDOUT, sprintf("Built %s (%d bytes)\nSHA-256 %s\n", $output, filesize($output), $sha256));
} finally {
    removeTree($stage);
}

function installProductionDependencies(string $stage): void
{
    $command = [
        ...composerCommand(),
        'install',
        '--working-dir=' . $stage,
        '--no-dev',
        '--prefer-dist',
        '--optimize-autoloader',
        '--no-interaction',
        '--no-progress',
        '--no-scripts',
        '--ignore-platform-req=ext-leveldb',
    ];
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, dirname($stage));
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Production dependency installation failed.');
    }
}

function pruneDevelopmentPackageFiles(string $stage): void
{
    $packages = [
        'data' => ['src', 'artifacts', 'manifests', 'composer.json', 'LICENSE', 'LICENSE-CODE', 'NOTICE', 'ATTRIBUTION.md'],
        'protocol' => ['src', 'composer.json', 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md'],
        'raknet' => ['src', 'composer.json', 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md'],
    ];
    foreach ($packages as $package => $allowed) {
        $source = $stage . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bedriox' . DIRECTORY_SEPARATOR . $package;
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException("The production bedriox/{$package} package is unavailable.");
        }
        $clean = $source . '.release-' . bin2hex(random_bytes(4));
        if (!mkdir($clean, 0777, false)) {
            throw new RuntimeException("Unable to prepare the bedriox/{$package} release package.");
        }
        try {
            foreach ($allowed as $entry) {
                $from = $source . DIRECTORY_SEPARATOR . $entry;
                if (!file_exists($from)) {
                    continue;
                }
                $to = $clean . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($from)) {
                    copyTree($from, $to);
                } else {
                    copyFile($from, $to);
                }
            }
            removeTree($source);
            if (!rename($clean, $source)) {
                throw new RuntimeException("Unable to publish the bedriox/{$package} release package.");
            }
        } catch (Throwable $failure) {
            removeTree($clean);
            throw $failure;
        }
    }
}

function writeLaunchers(string $directory, string $archive): void
{
    if ($archive !== 'Bedriox.phar') {
        throw new RuntimeException('The PHAR artifact name cannot be represented safely in a launcher.');
    }
    $windows = str_replace('@BEDRIOX_PHAR@', $archive, <<<'CMD'
@echo off
setlocal
set "BEDRIOX_ROOT=%~dp0"
set "BEDRIOX_RUNTIME_ROOT=%BEDRIOX_ROOT%bin"
set "BEDRIOX_RUNTIME_CACHE=%CD%\cache\runtime"
if not exist "%BEDRIOX_RUNTIME_CACHE%\opcache" mkdir "%BEDRIOX_RUNTIME_CACHE%\opcache" 2>nul
if not exist "%BEDRIOX_RUNTIME_CACHE%\opcache" (
    echo Bedriox could not create its runtime cache. 1>&2
    exit /b 1
)
set "PHPRC="
set "PHP_INI_SCAN_DIR="
set "OPENSSL_CONF=%BEDRIOX_RUNTIME_ROOT%\config\openssl.cnf"
set "OPENSSL_MODULES="
set "SSL_CERT_DIR="
set "SSL_CERT_FILE="
set "CURL_CA_BUNDLE="
"%BEDRIOX_RUNTIME_ROOT%\php.exe" -c "%BEDRIOX_RUNTIME_ROOT%\php.ini" "%BEDRIOX_ROOT%@BEDRIOX_PHAR@" %*
exit /b %ERRORLEVEL%
CMD
    ) . "\r\n";
    $unix = str_replace('@BEDRIOX_PHAR@', $archive, <<<'SH'
#!/bin/sh

set -eu

BEDRIOX_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
BEDRIOX_RUNTIME_ROOT="$BEDRIOX_ROOT/bin"
BEDRIOX_RUNTIME_CACHE="$PWD/cache/runtime"
mkdir -p -- "$BEDRIOX_RUNTIME_CACHE/opcache"
export BEDRIOX_RUNTIME_ROOT BEDRIOX_RUNTIME_CACHE

unset PHPRC OPENSSL_MODULES SSL_CERT_DIR SSL_CERT_FILE CURL_CA_BUNDLE
PHP_INI_SCAN_DIR=
OPENSSL_CONF="$BEDRIOX_RUNTIME_ROOT/config/openssl.cnf"
export PHP_INI_SCAN_DIR OPENSSL_CONF

unset LD_PRELOAD DYLD_INSERT_LIBRARIES
LD_LIBRARY_PATH="$BEDRIOX_RUNTIME_ROOT/lib"
DYLD_LIBRARY_PATH="$BEDRIOX_RUNTIME_ROOT/lib"
export LD_LIBRARY_PATH DYLD_LIBRARY_PATH

exec "$BEDRIOX_RUNTIME_ROOT/php" -c "$BEDRIOX_RUNTIME_ROOT/php.ini" "$BEDRIOX_ROOT/@BEDRIOX_PHAR@" "$@"
SH
    ) . "\n";
    $windowsPath = $directory . DIRECTORY_SEPARATOR . 'bedriox.cmd';
    $unixPath = $directory . DIRECTORY_SEPARATOR . 'bedriox';
    if (file_put_contents($windowsPath, $windows, LOCK_EX) !== strlen($windows)
        || file_put_contents($unixPath, $unix, LOCK_EX) !== strlen($unix)) {
        throw new RuntimeException('Unable to write the PHAR launchers.');
    }
    chmod($unixPath, 0755);
}

/** @return list<string> */
function composerCommand(): array
{
    $candidates = [dirname(__DIR__) . '/composer.phar'];
    $path = getenv('APPDATA');
    if (is_string($path)) {
        $candidates[] = $path . '/Composer/composer.phar';
    }
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return [PHP_BINARY, $candidate];
        }
    }
    $command = PHP_OS_FAMILY === 'Windows' ? 'where composer' : 'command -v composer';
    $resolved = shell_exec($command);
    if (!is_string($resolved) || trim($resolved) === '') {
        throw new RuntimeException('Composer could not be located for the release build.');
    }
    $first = preg_split('/\R/', trim($resolved))[0] ?? '';
    if (!is_file($first)) {
        throw new RuntimeException('The resolved Composer executable is unavailable.');
    }
    $siblingPhar = dirname($first) . DIRECTORY_SEPARATOR . 'composer.phar';
    if (is_file($siblingPhar)) {
        return [PHP_BINARY, $siblingPhar];
    }
    return str_ends_with(strtolower($first), '.phar') ? [PHP_BINARY, $first] : [$first];
}

/** @return array<string, array{bytes: int, sha256: string}> */
function inventory(string $root): array
{
    $files = [];
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || $entry->isLink() || !$entry->isFile()) {
            throw new RuntimeException('The PHAR payload contains an unsupported filesystem entry.');
        }
        if (count($files) >= MAXIMUM_ARCHIVE_FILES) {
            throw new RuntimeException('The PHAR payload contains too many files.');
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        $size = $entry->getSize();
        $bytes += $size;
        if ($bytes > MAXIMUM_ARCHIVE_BYTES) {
            throw new RuntimeException('The PHAR payload exceeds its size limit.');
        }
        $hash = hash_file('sha256', $entry->getPathname());
        if (!is_string($hash)) {
            throw new RuntimeException('A PHAR payload file could not be hashed.');
        }
        $files[$relative] = ['bytes' => $size, 'sha256' => $hash];
    }
    ksort($files, SORT_STRING);
    return $files;
}

/** @param array<string, array{bytes: int, sha256: string}> $files */
function payloadHash(array $files): string
{
    return hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function copyTree(string $source, string $destination): void
{
    if (is_link($source) || !is_dir($source) || (!is_dir($destination) && !mkdir($destination, 0777, true))) {
        throw new RuntimeException('Unable to create a PHAR staging directory.');
    }
    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Unable to inspect a PHAR payload entry.');
        }
        if ($entry->isLink()) {
            throw new RuntimeException('Symbolic links are not permitted in the PHAR payload.');
        }
        $target = $destination . DIRECTORY_SEPARATOR . $entry->getBasename();
        if ($entry->isDir()) {
            copyTree($entry->getPathname(), $target);
        } elseif ($entry->isFile()) {
            copyFile($entry->getPathname(), $target);
        } else {
            throw new RuntimeException('Unsupported PHAR payload entry.');
        }
    }
}

function copyFile(string $source, string $destination): void
{
    if (is_link($source) || !is_file($source) || !copy($source, $destination)) {
        throw new RuntimeException('Unable to copy a PHAR payload file.');
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Unable to inspect a PHAR staging entry.');
        }
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

function absolutePath(string $path, string $root): string
{
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D', $path) === 1) {
        return $path;
    }
    return $root . DIRECTORY_SEPARATOR . $path;
}
