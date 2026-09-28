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

use Closure;
use JsonException;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

final class RuntimeArchiveInstaller
{
    private const int MAX_ENTRIES = 4_096;
    private const int MAX_FILE_BYTES = 268_435_456;
    private const int MAX_EXPANDED_BYTES = 1_073_741_824;
    private const int MAX_PATH_BYTES = 220;

    /** @var Closure(string, RuntimeArtifactExpectation): RuntimeIdentity */
    private readonly Closure $probe;

    /** @var Closure(string, string): bool */
    private readonly Closure $move;

    /**
     * @param null|Closure(string, RuntimeArtifactExpectation): RuntimeIdentity $probe
     * @param null|Closure(string, string): bool                                $move
     */
    public function __construct(?Closure $probe = null, ?Closure $move = null)
    {
        $this->probe = $probe ?? self::probeRuntime(...);
        $this->move = $move ?? static fn(string $source, string $destination): bool => @rename($source, $destination);
    }

    public function install(
        string $archive,
        string $projectRoot,
        RuntimeArtifactExpectation $expected,
    ): void {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root) || is_link($root)) {
            throw new RuntimeException('The Bedriox project root must be a local directory.');
        }
        $archivePath = $this->localRegularFile($archive, 'runtime archive');
        if (basename($archivePath) !== $expected->file) {
            throw new RuntimeException('The local runtime archive name does not match the lock.');
        }

        $bin = $root . DIRECTORY_SEPARATOR . 'bin';
        $serverEntry = $bin . DIRECTORY_SEPARATOR . 'bedriox';
        if (!is_dir($bin) || is_link($bin) || !is_file($serverEntry) || is_link($serverEntry)) {
            throw new RuntimeException('The existing bin/bedriox server entry is unavailable.');
        }
        $nonce = bin2hex(random_bytes(12));
        $stage = $root . DIRECTORY_SEPARATOR . '.runtime-stage-' . $nonce;
        $backup = $root . DIRECTORY_SEPARATOR . '.runtime-backup-' . $nonce;
        if (!mkdir($stage, 0700, false)) {
            throw new RuntimeException('Could not create the same-volume runtime staging directory.');
        }

        try {
            $snapshot = $stage . DIRECTORY_SEPARATOR . 'runtime-archive.' . ($expected->format === 'zip' ? 'zip' : 'tar.gz');
            $this->copyVerifiedArchive($archivePath, $snapshot, $expected);
            $seen = [];
            $expandedBytes = 0;
            if ($expected->format === 'zip') {
                $this->extractZip($snapshot, $stage, $seen, $expandedBytes);
            } else {
                $this->extractTarGz($snapshot, $stage, $seen, $expandedBytes);
            }
            $stagedBin = $stage . DIRECTORY_SEPARATOR . 'bin';
            if (!is_dir($stagedBin) || is_link($stagedBin)) {
                throw new RuntimeException('The runtime archive does not contain a bin directory.');
            }
            $stagedServerEntry = $stagedBin . DIRECTORY_SEPARATOR . 'bedriox';
            if (file_exists($stagedServerEntry) || is_link($stagedServerEntry)) {
                throw new RuntimeException('The runtime archive must not replace bin/bedriox.');
            }
            $this->verifyManifest($stagedBin, $expected);
            $identity = ($this->probe)($stagedBin, $expected);
            $this->verifyIdentity($identity, $expected);

            $this->activateRuntime($stagedBin, $bin, $backup);
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            if (file_exists($stage) || is_link($stage)) {
                $this->removeTree($stage);
            }
        }
    }

    private function activateRuntime(string $stagedBin, string $bin, string $backup): void
    {
        $oldEntries = $this->validateExistingBin($bin);
        $newEntries = $this->directEntryNames($stagedBin, false);
        $backupBin = $backup . DIRECTORY_SEPARATOR . 'bin';
        if (file_exists($backup) || is_link($backup) || !mkdir($backupBin, 0700, true)) {
            throw new RuntimeException('Could not create same-volume runtime rollback storage.');
        }

        $movedOld = [];
        $movedNew = [];
        try {
            foreach ($oldEntries as $entry) {
                if (!(($this->move)(
                    $bin . DIRECTORY_SEPARATOR . $entry,
                    $backupBin . DIRECTORY_SEPARATOR . $entry,
                ))) {
                    throw new RuntimeException('Could not move an existing runtime entry into rollback storage.');
                }
                $movedOld[] = $entry;
            }
            foreach ($newEntries as $entry) {
                if (!(($this->move)(
                    $stagedBin . DIRECTORY_SEPARATOR . $entry,
                    $bin . DIRECTORY_SEPARATOR . $entry,
                ))) {
                    throw new RuntimeException('Could not activate a staged runtime entry.');
                }
                $movedNew[] = $entry;
            }
        } catch (Throwable $exception) {
            if (!$this->rollbackRuntime($stagedBin, $bin, $backupBin, $movedNew, $movedOld)) {
                throw new RuntimeException(
                    'Runtime activation failed and automatic rollback was incomplete; rollback storage was preserved.',
                    previous: $exception,
                );
            }
            throw new RuntimeException('Runtime activation failed; the existing runtime was restored.', previous: $exception);
        }

        $this->removeTree($backup);
    }

    /**
     * @param list<string> $movedNew
     * @param list<string> $movedOld
     */
    private function rollbackRuntime(
        string $stagedBin,
        string $bin,
        string $backupBin,
        array $movedNew,
        array $movedOld,
    ): bool {
        $complete = true;
        foreach (array_reverse($movedNew) as $entry) {
            if (!(($this->move)(
                $bin . DIRECTORY_SEPARATOR . $entry,
                $stagedBin . DIRECTORY_SEPARATOR . $entry,
            ))) {
                $complete = false;
            }
        }
        foreach (array_reverse($movedOld) as $entry) {
            if (!(($this->move)(
                $backupBin . DIRECTORY_SEPARATOR . $entry,
                $bin . DIRECTORY_SEPARATOR . $entry,
            ))) {
                $complete = false;
            }
        }
        if ($complete) {
            $this->removeTree(dirname($backupBin));
        }

        return $complete;
    }

    /** @return list<string> */
    private function validateExistingBin(string $bin): array
    {
        $entries = $this->directEntryNames($bin, true);
        $files = [];
        $directories = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($bin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $entryCount = 0;
        foreach ($iterator as $entry) {
            if (++$entryCount > self::MAX_ENTRIES) {
                throw new RuntimeException('The existing bin directory exceeds its entry limit.');
            }
            if (!$entry instanceof SplFileInfo || $entry->isLink()
                || (!$entry->isFile() && !$entry->isDir())
            ) {
                throw new RuntimeException('The existing bin directory contains a link or special entry.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($bin) + 1));
            if ($entry->isDir()) {
                $directories[] = $relative;
                continue;
            }
            if ($relative === 'bedriox') {
                continue;
            }
            $bytes = $entry->getSize();
            $hash = hash_file('sha256', $entry->getPathname());
            if (!is_string($hash)) {
                throw new RuntimeException('An existing runtime entry could not be hashed.');
            }
            $files[$relative] = ['bytes' => $bytes, 'sha256' => $hash];
        }

        $manifestPath = $bin . DIRECTORY_SEPARATOR . 'runtime-manifest.json';
        if (!file_exists($manifestPath)) {
            return $entries;
        }
        $manifest = RuntimeManifest::load($manifestPath);
        unset($files['runtime-manifest.json']);
        ksort($files, SORT_STRING);
        if ($files !== $manifest->files) {
            throw new RuntimeException('The existing runtime file inventory does not match its manifest.');
        }
        $expectedDirectories = [];
        foreach (array_keys($manifest->files) as $relative) {
            $parent = dirname($relative);
            while ($parent !== '.') {
                $expectedDirectories[$parent] = true;
                $parent = dirname($parent);
            }
        }
        sort($directories, SORT_STRING);
        $declaredDirectories = array_keys($expectedDirectories);
        sort($declaredDirectories, SORT_STRING);
        if ($directories !== $declaredDirectories) {
            throw new RuntimeException('The existing runtime contains an unknown directory.');
        }

        return $entries;
    }

    /** @return list<string> */
    private function directEntryNames(string $directory, bool $excludeServerEntry): array
    {
        $names = [];
        $iterator = new \FilesystemIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->isLink()
                || (!$entry->isFile() && !$entry->isDir())
            ) {
                throw new RuntimeException('A runtime directory contains a link or special entry.');
            }
            $name = $entry->getFilename();
            if (strcasecmp($name, 'bedriox') === 0) {
                if (!$excludeServerEntry || $name !== 'bedriox' || !$entry->isFile()) {
                    throw new RuntimeException('A runtime directory contains an invalid bin/bedriox entry.');
                }
                continue;
            }
            $names[] = $name;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    private function copyVerifiedArchive(
        string $source,
        string $destination,
        RuntimeArtifactExpectation $expected,
    ): void {
        $input = @fopen($source, 'rb');
        $output = @fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Could not create a private runtime archive snapshot.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 65_536);
                if ($chunk === false) {
                    throw new RuntimeException('The local runtime archive could not be read.');
                }
                if ($chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > $expected->bytes) {
                    throw new RuntimeException('The local runtime archive size does not match the lock.');
                }
                hash_update($hash, $chunk);
                $this->writeAll($output, $chunk);
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($bytes !== $expected->bytes) {
            throw new RuntimeException('The local runtime archive size does not match the lock.');
        }
        if (!hash_equals($expected->sha256, hash_final($hash))) {
            throw new RuntimeException('The local runtime archive SHA-256 does not match the lock.');
        }
    }

    /** @param array<string, string> $seen */
    private function extractZip(string $archive, string $stage, array &$seen, int &$expandedBytes): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP support is required to install this runtime archive.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The runtime ZIP archive could not be opened.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('The runtime ZIP entry count is outside its limit.');
            }
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if ($stat === false) {
                    throw new RuntimeException('The runtime ZIP contains unreadable entry metadata.');
                }
                if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                    throw new RuntimeException('Encrypted runtime ZIP entries are not supported.');
                }
                $directory = str_ends_with($stat['name'], '/');
                $operatingSystem = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                    if (!is_int($attributes)) {
                        throw new RuntimeException('The runtime ZIP contains unreadable external attributes.');
                    }
                    $type = ($attributes >> 16) & 0170000;
                    if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                        throw new RuntimeException('The runtime ZIP contains a link or special entry.');
                    }
                    if ($type === 0040000) {
                        $directory = true;
                    }
                }
                $path = $this->registerPath($stat['name'], $directory, $seen);
                if ($directory) {
                    $this->ensureDirectory($stage, $path);
                    continue;
                }
                $this->accountFile($stat['size'], $expandedBytes);
                $stream = $zip->getStreamIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_resource($stream)) {
                    throw new RuntimeException('A runtime ZIP entry could not be opened.');
                }
                try {
                    $this->writeStream($stream, $stage, $path, $stat['size']);
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, string> $seen */
    private function extractTarGz(string $archive, string $stage, array &$seen, int &$expandedBytes): void
    {
        $stream = gzopen($archive, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The runtime tar.gz archive could not be opened.');
        }
        $entries = 0;
        $zeroBlocks = 0;
        try {
            while (true) {
                $header = $this->readGzipExact($stream, 512);
                if ($header === str_repeat("\0", 512)) {
                    if (++$zeroBlocks === 2) {
                        break;
                    }
                    continue;
                }
                if ($zeroBlocks !== 0) {
                    throw new RuntimeException('The runtime tar archive has an invalid end marker.');
                }
                if (++$entries > self::MAX_ENTRIES) {
                    throw new RuntimeException('The runtime tar entry count exceeds its limit.');
                }
                $this->verifyTarChecksum($header);
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
                $type = $header[156];
                $directory = $type === '5';
                if (!$directory && $type !== "\0" && $type !== '0') {
                    throw new RuntimeException('The runtime tar contains a link or special entry.');
                }
                $size = $this->tarNumber(substr($header, 124, 12), 'entry size');
                if ($directory && $size !== 0) {
                    throw new RuntimeException('A runtime tar directory contains data.');
                }
                $path = $this->registerPath($name, $directory, $seen);
                if ($directory) {
                    $this->ensureDirectory($stage, $path);
                } else {
                    $this->accountFile($size, $expandedBytes);
                    $this->writeGzipEntry($stream, $stage, $path, $size);
                    $padding = (512 - ($size % 512)) % 512;
                    if ($padding > 0) {
                        $this->readGzipExact($stream, $padding);
                    }
                    $mode = $this->tarNumber(substr($header, 100, 8), 'entry mode') & 0777;
                    @chmod($stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path), $mode);
                }
            }
        } finally {
            gzclose($stream);
        }
        if ($entries === 0) {
            throw new RuntimeException('The runtime tar archive is empty.');
        }
    }

    /** @param resource $stream */
    private function writeGzipEntry($stream, string $stage, string $path, int $size): void
    {
        $destination = $this->openDestination($stage, $path);
        try {
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = gzread($stream, min(65_536, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new RuntimeException('A runtime tar entry ended before its declared size.');
                }
                $this->writeAll($destination, $chunk);
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($destination);
        }
    }

    /** @param resource $stream */
    private function writeStream($stream, string $stage, string $path, int $size): void
    {
        $destination = $this->openDestination($stage, $path);
        $written = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    throw new RuntimeException('A runtime archive entry could not be read.');
                }
                if ($chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $size) {
                    throw new RuntimeException('A runtime archive entry exceeds its declared size.');
                }
                $this->writeAll($destination, $chunk);
            }
        } finally {
            fclose($destination);
        }
        if ($written !== $size) {
            throw new RuntimeException('A runtime archive entry size does not match its metadata.');
        }
    }

    /** @return resource */
    private function openDestination(string $stage, string $path)
    {
        $native = str_replace('/', DIRECTORY_SEPARATOR, $path);
        $parent = dirname($native);
        if ($parent !== '.') {
            $this->ensureDirectory($stage, str_replace(DIRECTORY_SEPARATOR, '/', $parent));
        }
        $destination = $stage . DIRECTORY_SEPARATOR . $native;
        $stream = @fopen($destination, 'xb');
        if ($stream === false) {
            throw new RuntimeException('A staged runtime file could not be created exclusively.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $contents): void
    {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($stream, substr($contents, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('A staged runtime file could not be written completely.');
            }
            $offset += $written;
        }
    }

    /** @param array<string, string> $seen */
    private function registerPath(string $raw, bool $directory, array &$seen): string
    {
        if ($raw === '' || str_contains($raw, "\0") || str_contains($raw, '\\') || strlen($raw) > self::MAX_PATH_BYTES
            || str_starts_with($raw, '/') || preg_match('/^[A-Za-z]:/', $raw) === 1
        ) {
            throw new RuntimeException('The runtime archive contains an unsafe path.');
        }
        $path = $directory ? rtrim($raw, '/') : $raw;
        $segments = explode('/', $path);
        if ($segments[0] !== 'bin') {
            throw new RuntimeException('Every runtime archive entry must be rooted under bin/.');
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/^[A-Za-z0-9._+ -]+$/D', $segment) !== 1
                || str_ends_with($segment, '.') || str_ends_with($segment, ' ')
                || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?$/iD', $segment) === 1
            ) {
                throw new RuntimeException('The runtime archive contains a path unsafe on Windows.');
            }
        }
        if (strcasecmp($path, 'bin/bedriox') === 0) {
            throw new RuntimeException('The runtime archive must not contain bin/bedriox.');
        }
        $folded = strtolower($path);
        if (isset($seen[$folded])) {
            $kind = $seen[$folded] === $path ? 'duplicate' : 'case-colliding';
            throw new RuntimeException('The runtime archive contains a ' . $kind . ' path.');
        }
        $seen[$folded] = $path;

        return $path;
    }

    private function ensureDirectory(string $stage, string $path): void
    {
        $current = $stage;
        foreach (explode('/', $path) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_dir($current) && !is_link($current)) {
                continue;
            }
            if (file_exists($current) || is_link($current) || !mkdir($current, 0700, false)) {
                throw new RuntimeException('A staged runtime directory could not be created safely.');
            }
        }
    }

    private function accountFile(int $size, int &$expandedBytes): void
    {
        if ($size < 0 || $size > self::MAX_FILE_BYTES || $expandedBytes > self::MAX_EXPANDED_BYTES - $size) {
            throw new RuntimeException('The expanded runtime archive exceeds its file or total byte limit.');
        }
        $expandedBytes += $size;
    }

    private function verifyManifest(string $runtimeRoot, RuntimeArtifactExpectation $expected): void
    {
        $path = $runtimeRoot . DIRECTORY_SEPARATOR . 'runtime-manifest.json';
        $manifest = $this->localRegularFile($path, 'runtime manifest');
        $hash = hash_file('sha256', $manifest);
        if (!is_string($hash) || !hash_equals($expected->manifestSha256, $hash)) {
            throw new RuntimeException('The staged runtime manifest SHA-256 does not match the lock.');
        }
        $contents = file_get_contents($manifest);
        if ($contents === false || strlen($contents) > 1_048_576) {
            throw new RuntimeException('The staged runtime manifest exceeds its size limit.');
        }
        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The staged runtime manifest is invalid JSON.', previous: $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || ($decoded['schemaVersion'] ?? null) !== $expected->manifestSchema
            || ($decoded['target'] ?? null) !== $expected->target
            || ($decoded['phpVersion'] ?? null) !== $expected->phpVersion
            || ($decoded['threadSafe'] ?? null) !== $expected->threadSafe
        ) {
            throw new RuntimeException('The staged runtime manifest identity does not match the lock.');
        }
    }

    private function verifyIdentity(RuntimeIdentity $identity, RuntimeArtifactExpectation $expected): void
    {
        if ($identity->phpVersion !== $expected->phpVersion || $identity->threadSafe !== $expected->threadSafe) {
            throw new RuntimeException('The staged PHP runtime identity does not match the lock.');
        }
        $loaded = [];
        foreach ($identity->extensions as $extension) {
            $normalized = strtolower($extension);
            if (isset($loaded[$normalized])) {
                throw new RuntimeException('The staged PHP runtime returned a duplicate extension name.');
            }
            $loaded[$normalized] = $extension;
        }
        $required = [];
        foreach ($expected->extensions as $extension) {
            $required[strtolower($extension)] = $extension;
        }
        ksort($loaded, SORT_STRING);
        ksort($required, SORT_STRING);
        if (array_keys($loaded) !== array_keys($required)) {
            throw new RuntimeException('The staged PHP runtime does not match the locked extension set.');
        }
    }

    private static function probeRuntime(string $runtimeRoot, RuntimeArtifactExpectation $expected): RuntimeIdentity
    {
        $executable = $runtimeRoot . DIRECTORY_SEPARATOR
            . (str_starts_with($expected->target, 'windows-') ? 'php.exe' : 'php');
        $ini = $runtimeRoot . DIRECTORY_SEPARATOR . 'php.ini';
        if (!is_file($executable) || is_link($executable) || !is_file($ini) || is_link($ini)) {
            throw new RuntimeException('The staged runtime executable or configuration is missing.');
        }
        $environment = getenv();
        $cacheRoot = dirname($runtimeRoot) . DIRECTORY_SEPARATOR . 'runtime-cache';
        $opcodeCache = $cacheRoot . DIRECTORY_SEPARATOR . 'opcache';
        if (!is_dir($opcodeCache) && !mkdir($opcodeCache, 0700, true)) {
            throw new RuntimeException('The staged runtime opcode cache could not be created.');
        }
        $environment['BEDRIOX_RUNTIME_ROOT'] = $runtimeRoot;
        $environment['BEDRIOX_RUNTIME_CACHE'] = $cacheRoot;
        $environment['PHPRC'] = '';
        $environment['PHP_INI_SCAN_DIR'] = '';
        $environment['OPENSSL_CONF'] = $runtimeRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        $environment['OPENSSL_MODULES'] = '';
        $environment['SSL_CERT_DIR'] = '';
        $environment['SSL_CERT_FILE'] = '';
        $environment['CURL_CA_BUNDLE'] = '';
        $environment['LD_LIBRARY_PATH'] = $runtimeRoot . DIRECTORY_SEPARATOR . 'lib';
        $environment['DYLD_LIBRARY_PATH'] = $runtimeRoot . DIRECTORY_SEPARATOR . 'lib';
        $process = proc_open(
            [
                $executable,
                '-c',
                $ini,
                '-r',
                'echo json_encode([PHP_VERSION, (bool) PHP_ZTS, get_loaded_extensions()], JSON_THROW_ON_ERROR);',
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $runtimeRoot,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The staged PHP runtime could not be started by exact path.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 1_048_577);
        $stderr = stream_get_contents($pipes[2], 1_048_577);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || !is_string($stdout) || strlen($stdout) > 1_048_576
            || !is_string($stderr) || strlen($stderr) > 1_048_576
        ) {
            throw new RuntimeException('The staged PHP runtime identity probe failed.');
        }
        try {
            $identity = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The staged PHP runtime returned an invalid identity.', previous: $exception);
        }
        if (!is_array($identity) || count($identity) !== 3 || !is_string($identity[0] ?? null)
            || !is_bool($identity[1] ?? null) || !is_array($identity[2] ?? null) || !array_is_list($identity[2])
        ) {
            throw new RuntimeException('The staged PHP runtime returned an invalid identity shape.');
        }
        $extensions = [];
        foreach ($identity[2] as $extension) {
            if (!is_string($extension)) {
                throw new RuntimeException('The staged PHP runtime returned an invalid extension name.');
            }
            $extensions[] = $extension;
        }

        return new RuntimeIdentity($identity[0], $identity[1], $extensions);
    }

    private function localRegularFile(string $path, string $label): string
    {
        if (!stream_is_local($path) || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            throw new RuntimeException('The ' . $label . ' must be a local path.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || is_link($resolved)) {
            throw new RuntimeException('The ' . $label . ' must be a regular local file.');
        }

        return $resolved;
    }

    /** @param resource $stream */
    private function readGzipExact($stream, int $bytes): string
    {
        $contents = '';
        while (strlen($contents) < $bytes) {
            $chunk = gzread($stream, $bytes - strlen($contents));
            if (!is_string($chunk) || $chunk === '') {
                throw new RuntimeException('The runtime tar archive ended unexpectedly.');
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    private function verifyTarChecksum(string $header): void
    {
        $expected = $this->tarNumber(substr($header, 148, 8), 'header checksum');
        $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $actual = 0;
        for ($index = 0; $index < 512; ++$index) {
            $actual += ord($checksumHeader[$index]);
        }
        if ($actual !== $expected) {
            throw new RuntimeException('The runtime tar header checksum is invalid.');
        }
    }

    private function tarNumber(string $field, string $label): int
    {
        if ((ord($field[0]) & 0x80) !== 0) {
            throw new RuntimeException('Binary runtime tar ' . $label . ' values are unsupported.');
        }
        $value = trim($field, " \0");
        if ($value === '' || preg_match('/^[0-7]+$/D', $value) !== 1) {
            throw new RuntimeException('The runtime tar ' . $label . ' is invalid.');
        }
        $parsed = octdec($value);
        if (!is_int($parsed) || $parsed < 0 || $parsed > self::MAX_EXPANDED_BYTES) {
            throw new RuntimeException('The runtime tar ' . $label . ' exceeds its limit.');
        }

        return $parsed;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Could not remove a runtime staging file.');
            }

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \FilesystemIterator(
            $path,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::KEY_AS_PATHNAME,
        );
        foreach ($iterator as $entryPath => $_entry) {
            $this->removeTree((string) $entryPath);
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Could not remove a runtime staging directory.');
        }
    }

}
