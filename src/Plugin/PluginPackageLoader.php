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

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Plugin\Plugin;
use Bedriox\Api\Plugin\PluginContext;
use Closure;
use FilesystemIterator;
use Phar;
use PharFileInfo;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/** Discovers validated PHAR plugins without executing plugin code. */
final class PluginPackageLoader
{
    public const string API_VERSION = '0.3.0';
    private const int MAXIMUM_ARCHIVE_BYTES = 16_777_216;
    private const int MAXIMUM_ENTRIES = 2_048;
    private const int MAXIMUM_ENTRY_BYTES = 8_388_608;
    private const int MAXIMUM_UNCOMPRESSED_BYTES = 67_108_864;

    public function __construct(private readonly int $maximumPlugins = 64)
    {
        if ($this->maximumPlugins < 0 || $this->maximumPlugins > 256) {
            throw new PluginException('Plugin count limit must be between 0 and 256.');
        }
    }

    /**
     * @param Closure(string, Throwable): void|null $onFailure
     * @return list<PluginPackage>
     */
    public function discover(string $pluginsDirectory, ?Closure $onFailure = null): array
    {
        if (!is_dir($pluginsDirectory) && !@mkdir($pluginsDirectory, 0o775, true) && !is_dir($pluginsDirectory)) {
            throw new PluginException('Unable to create the plugins directory.');
        }
        $root = realpath($pluginsDirectory);
        if ($root === false) {
            throw new PluginException('Unable to resolve the plugins directory.');
        }
        $archives = [];
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->isLink()
                || strtolower($entry->getExtension()) !== 'phar') {
                continue;
            }
            $archives[] = $entry->getPathname();
        }
        natcasesort($archives);
        $archives = array_values($archives);
        if (count($archives) > $this->maximumPlugins) {
            throw new PluginException('Plugin archive count exceeds the configured limit.');
        }

        $packages = [];
        $names = [];
        foreach ($archives as $archive) {
            try {
                $package = $this->inspect($root, $archive);
                $key = strtolower($package->manifest->name);
                if (isset($names[$key])) {
                    throw new PluginException("Duplicate plugin: {$package->manifest->name}");
                }
                $names[$key] = true;
                $packages[] = $package;
            } catch (Throwable $failure) {
                if ($onFailure !== null) {
                    $onFailure(basename($archive), $failure);
                }
            }
        }

        return $packages;
    }

    private function inspect(string $root, string $archivePath): PluginPackage
    {
        $resolved = realpath($archivePath);
        $size = $resolved === false ? false : filesize($resolved);
        if ($resolved === false || !$this->within($root, $resolved) || $size === false || $size > self::MAXIMUM_ARCHIVE_BYTES) {
            throw new PluginException('Plugin archive path or size is invalid.');
        }
        try {
            $archive = new Phar($resolved);
        } catch (Throwable $failure) {
            throw new PluginException('Plugin archive could not be opened.', 0, $failure);
        }
        $signature = $archive->getSignature();
        if (!in_array($signature['hash_type'], ['SHA-256', 'SHA-512', 'OpenSSL', 'OpenSSL_SHA256', 'OpenSSL_SHA512'], true)) {
            throw new PluginException('Plugin archive must have a supported strong PHAR signature.');
        }
        $archiveDigest = hash_file('sha256', $resolved);
        if (!is_string($archiveDigest)) {
            throw new PluginException('Plugin archive identity could not be calculated.');
        }
        if ($archive->hasMetadata()) {
            throw new PluginException('Plugin archives may not contain serialized archive metadata.');
        }
        $entries = 0;
        $uncompressedBytes = 0;
        $prefix = 'phar://' . str_replace('\\', '/', $resolved) . '/';
        /** @var PharFileInfo $entry */
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            ++$entries;
            if ($entries > self::MAXIMUM_ENTRIES || $entry->isLink() || $entry->hasMetadata()) {
                throw new PluginException('Plugin archive contains too many, linked, or metadata-bearing entries.');
            }
            $path = str_replace('\\', '/', $entry->getPathname());
            $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';
            if ($relative === '' || strlen($relative) > 512 || str_contains($relative, "\0")
                || preg_match('#(?:^|/)\.\.(?:/|$)#', $relative) === 1 || str_starts_with($relative, '/')) {
                throw new PluginException('Plugin archive contains an unsafe entry path.');
            }
            $entrySize = $entry->getSize();
            $uncompressedBytes += $entrySize;
            if ($entrySize > self::MAXIMUM_ENTRY_BYTES || $uncompressedBytes > self::MAXIMUM_UNCOMPRESSED_BYTES) {
                throw new PluginException('Plugin archive expands beyond its bounded limits.');
            }
        }
        $manifestPath = $prefix . 'plugin.json';
        $manifest = (new PluginManifestParser())->parseFile($manifestPath);
        if (!$this->supportsApi($manifest->api)) {
            throw new PluginException("Plugin {$manifest->name} requires unsupported API {$manifest->api}.");
        }
        if (!is_dir($prefix . 'src')) {
            throw new PluginException("Plugin {$manifest->name} must contain a src directory.");
        }
        $source = $prefix . 'src/';
        $namespacePrefix = $manifest->namespace . '\\';
        $autoloader = static function (string $class) use ($namespacePrefix, $source): void {
            if (!str_starts_with($class, $namespacePrefix)) {
                return;
            }
            $relative = substr($class, strlen($namespacePrefix));
            if ($relative === '' || preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/D', $relative) !== 1) {
                return;
            }
            $path = $source . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        };
        $instantiate = static function (PluginContext $context) use ($manifest, $autoloader): Plugin {
            spl_autoload_register($autoloader, true, true);
            try {
                if (!class_exists($manifest->main)) {
                    throw new PluginException("Plugin entry point {$manifest->main} was not found.");
                }
                if (!is_subclass_of($manifest->main, Plugin::class)) {
                    throw new PluginException("Plugin entry point {$manifest->main} must extend " . Plugin::class . '.');
                }

                return new ($manifest->main)($context);
            } catch (Throwable $failure) {
                spl_autoload_unregister($autoloader);
                throw $failure;
            }
        };

        return new PluginPackage(
            $resolved,
            $manifest,
            $autoloader,
            $instantiate,
            new PluginArchiveIdentity(
                $resolved,
                $manifest->name,
                $manifest->version,
                $archiveDigest,
                $signature['hash_type'],
                strtolower($signature['hash']),
            ),
        );
    }

    private function supportsApi(string $constraint): bool
    {
        return in_array($constraint, [
            '0.3', '0.3.0', '^0.3', '^0.3.0', '~0.3', '~0.3.0',
        ], true);
    }

    private function within(string $root, string $candidate): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $candidate = str_replace('\\', '/', $candidate);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $candidate = strtolower($candidate);
        }

        return str_starts_with($candidate, $root);
    }
}
