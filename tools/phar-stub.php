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

Phar::mapPhar('bedriox.phar');

const BEDRIOX_PHAR_VERSION = '@BEDRIOX_VERSION@';
const BEDRIOX_PHAR_MAXIMUM_FILES = 8_192;
const BEDRIOX_PHAR_MAXIMUM_BYTES = 536_870_912;

try {
    $archive = realpath(__FILE__);
    if (!is_string($archive)) {
        $invoked = $argv[0] ?? null;
        $archive = is_string($invoked) ? realpath($invoked) : false;
    }
    $workingDirectory = getcwd();
    if (!is_string($archive) || !is_file($archive) || is_link($archive) || !is_string($workingDirectory)) {
        throw new RuntimeException('Unable to resolve the Bedriox application paths.');
    }
    $distributionRoot = dirname($archive);
    $runtimeRoot = $distributionRoot . DIRECTORY_SEPARATOR . 'bin';
    $runtimeCache = $workingDirectory . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'runtime';
    putenv('BEDRIOX_RUNTIME_ROOT=' . $runtimeRoot);
    putenv('BEDRIOX_RUNTIME_CACHE=' . $runtimeCache);
    putenv('OPENSSL_CONF=' . $runtimeRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf');
    putenv('SSL_CERT_FILE=' . $runtimeRoot . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem');
    putenv('CURL_CA_BUNDLE=' . $runtimeRoot . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem');

    $applicationRoot = bedrioxExtractApplication($workingDirectory);
    require_once $applicationRoot . '/src/Environment/RuntimeEnvironmentException.php';
    require_once $applicationRoot . '/src/Environment/RuntimeProcessIdentity.php';
    require_once $applicationRoot . '/src/Environment/RuntimeManifest.php';
    require_once $applicationRoot . '/src/Environment/RuntimeArtifactExpectation.php';
    require_once $applicationRoot . '/src/Environment/RuntimeEnvironmentValidator.php';
    \Bedriox\Server\Environment\RuntimeEnvironmentValidator::validate(
        $distributionRoot,
        $runtimeRoot,
        lockFile: $applicationRoot . '/bedriox.lock.json',
    );
    require $applicationRoot . '/vendor/autoload.php';
    $application = new \Bedriox\Server\Bedriox();
    exit($application->run(
        array_slice($argv, 1),
        static function (string $message): void { fwrite(STDOUT, $message); },
        static function (string $message): void { fwrite(STDERR, $message); },
    ));
} catch (Throwable $failure) {
    fwrite(STDERR, 'Bedriox PHAR startup failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

function bedrioxExtractApplication(string $workingDirectory): string
{
    $manifestBytes = file_get_contents('phar://bedriox.phar/application-manifest.json');
    if (!is_string($manifestBytes) || strlen($manifestBytes) > 4_194_304) {
        throw new RuntimeException('The Bedriox application manifest is unavailable.');
    }
    $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1
        || ($manifest['version'] ?? null) !== BEDRIOX_PHAR_VERSION
        || !is_string($manifest['payload_hash'] ?? null)
        || preg_match('/^[0-9a-f]{64}$/D', $manifest['payload_hash']) !== 1
        || !is_array($manifest['files'] ?? null) || array_is_list($manifest['files'])
        || count($manifest['files']) > BEDRIOX_PHAR_MAXIMUM_FILES) {
        throw new RuntimeException('The Bedriox application manifest is invalid.');
    }
    $cache = $workingDirectory . DIRECTORY_SEPARATOR . 'cache';
    $applications = $cache . DIRECTORY_SEPARATOR . 'application';
    foreach ([$cache, $applications] as $directory) {
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0777, false) && !is_dir($directory))) {
            throw new RuntimeException('The Bedriox application cache is unavailable.');
        }
    }
    $target = $applications . DIRECTORY_SEPARATOR . BEDRIOX_PHAR_VERSION . '-' . substr($manifest['payload_hash'], 0, 16);
    $marker = $target . DIRECTORY_SEPARATOR . '.ready';
    if (is_file($marker) && hash_equals(trim((string) file_get_contents($marker)), $manifest['payload_hash'])) {
        return $target;
    }

    $lock = fopen($applications . DIRECTORY_SEPARATOR . '.extract.lock', 'c+b');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to lock the Bedriox application cache.');
    }
    try {
        if (is_file($marker) && hash_equals(trim((string) file_get_contents($marker)), $manifest['payload_hash'])) {
            return $target;
        }
        if (file_exists($target)) {
            throw new RuntimeException('The cached Bedriox application is incomplete; remove it and retry.');
        }
        $stage = $applications . DIRECTORY_SEPARATOR . '.stage-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0777, false)) {
            throw new RuntimeException('Unable to create the Bedriox extraction stage.');
        }
        $totalBytes = 0;
        try {
            foreach ($manifest['files'] as $relative => $metadata) {
                if (!is_string($relative) || preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/\\-]+$#D', $relative) !== 1
                    || !is_array($metadata) || !is_int($metadata['bytes'] ?? null)
                    || $metadata['bytes'] < 0 || !is_string($metadata['sha256'] ?? null)
                    || preg_match('/^[0-9a-f]{64}$/D', $metadata['sha256']) !== 1) {
                    throw new RuntimeException('The Bedriox application manifest contains an invalid file.');
                }
                $totalBytes += $metadata['bytes'];
                if ($totalBytes > BEDRIOX_PHAR_MAXIMUM_BYTES) {
                    throw new RuntimeException('The Bedriox application payload exceeds its size limit.');
                }
                $destination = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                    throw new RuntimeException('Unable to create a Bedriox application directory.');
                }
                $source = fopen('phar://bedriox.phar/application/' . $relative, 'rb');
                $output = fopen($destination, 'xb');
                if (!is_resource($source) || !is_resource($output)) {
                    throw new RuntimeException('Unable to extract a Bedriox application file.');
                }
                try {
                    $copied = stream_copy_to_stream($source, $output, $metadata['bytes'] + 1);
                } finally {
                    fclose($source);
                    fclose($output);
                }
                if ($copied !== $metadata['bytes'] || !hash_equals($metadata['sha256'], (string) hash_file('sha256', $destination))) {
                    throw new RuntimeException('A Bedriox application file failed integrity verification.');
                }
            }
            if (file_put_contents($stage . DIRECTORY_SEPARATOR . '.ready', $manifest['payload_hash'] . "\n", LOCK_EX) !== 65
                || !rename($stage, $target)) {
                throw new RuntimeException('Unable to publish the Bedriox application cache.');
            }
        } catch (Throwable $failure) {
            bedrioxRemoveExtractionStage($stage, $applications);
            throw $failure;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $target;
}

function bedrioxRemoveExtractionStage(string $stage, string $applications): void
{
    $prefix = rtrim($applications, '\\/') . DIRECTORY_SEPARATOR . '.stage-';
    if (!str_starts_with($stage, $prefix) || !is_dir($stage) || is_link($stage)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Unable to inspect a Bedriox extraction entry.');
        }
        if ($entry->isDir() && !$entry->isLink()) { @rmdir($entry->getPathname()); }
        else { @unlink($entry->getPathname()); }
    }
    @rmdir($stage);
}

__HALT_COMPILER();
