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

use Bedriox\Api\ResourcePack\ResourcePackDefinition;
use Bedriox\Api\ResourcePack\ResourcePackInfo;
use Bedriox\Server\Plugin\PluginException;

final class ResourcePackRegistry
{
    /** @var array<string, array{archive: ResourcePackArchive, owner: string, required: bool}> */
    private array $registered = [];
    private bool $sealed = false;

    public function __construct(
        private readonly ResourcePackCatalog $configured,
        private readonly string $cacheDirectory,
        private readonly ResourcePackCatalogLoader $loader = new ResourcePackCatalogLoader(),
    ) {}

    public function register(string $owner, ResourcePackDefinition $definition): ResourcePackInfo
    {
        if ($this->sealed) {
            throw new PluginException('Resource packs may be registered only while plugins are starting.');
        }
        if ($this->snapshot()->count() >= 32) {
            throw new PluginException('The resource-pack stack already contains 32 packs.');
        }
        $archive = $this->loader->loadFile(
            $definition->archivePath,
            $definition->subPackName,
            $this->cacheDirectory,
        );
        try {
            new ResourcePackCatalog([...$this->snapshot()->stack, $archive]);
        } catch (\InvalidArgumentException $failure) {
            throw new PluginException($failure->getMessage(), 0, $failure);
        }
        $key = strtolower($archive->id);
        if ($this->configured->pack($key) !== null || isset($this->registered[$key])) {
            throw new PluginException("Resource pack {$archive->id} is already registered.");
        }
        foreach ($archive->dependencies as $dependency => $version) {
            $configured = $this->configured->pack($dependency);
            $registered = $this->registered[$dependency] ?? null;
            if ($configured !== null && $configured->version === $version) {
                continue;
            }
            if ($registered !== null && strcasecmp($registered['owner'], $owner) === 0
                && $registered['archive']->version === $version) {
                continue;
            }
            throw new PluginException(
                "Resource pack {$archive->id} requires {$dependency} version {$version} earlier in the same owned stack.",
            );
        }
        $this->registered[$key] = [
            'archive' => $archive,
            'owner' => $owner,
            'required' => $definition->required,
        ];

        return $this->info($archive, $definition->required, $owner);
    }

    public function remove(string $owner, string $uuid): void
    {
        $key = strtolower($uuid);
        $entry = $this->registered[$key] ?? null;
        if ($entry === null || strcasecmp($entry['owner'], $owner) !== 0) {
            throw new PluginException("Plugin {$owner} does not own resource pack {$uuid}.");
        }
        foreach ($this->registered as $candidate) {
            if (strcasecmp($candidate['owner'], $owner) === 0
                && isset($candidate['archive']->dependencies[$key])) {
                throw new PluginException("Resource pack {$uuid} is still required by {$candidate['archive']->id}.");
            }
        }
        unset($this->registered[$key]);
    }

    public function removeIfOwned(string $owner, string $uuid): void
    {
        $entry = $this->registered[strtolower($uuid)] ?? null;
        if ($entry !== null && strcasecmp($entry['owner'], $owner) === 0) {
            unset($this->registered[strtolower($uuid)]);
        }
    }

    /** @return list<ResourcePackInfo> */
    public function all(): array
    {
        $packs = [];
        foreach ($this->configured->stack as $archive) {
            $packs[] = $this->info($archive, $this->configured->required, null);
        }
        foreach ($this->registered as $entry) {
            $packs[] = $this->info($entry['archive'], $entry['required'], $entry['owner']);
        }

        return $packs;
    }

    public function snapshot(): ResourcePackCatalog
    {
        $stack = $this->configured->stack;
        $required = $this->configured->required;
        foreach ($this->registered as $entry) {
            $stack[] = $entry['archive'];
            $required = $required || $entry['required'];
        }

        return new ResourcePackCatalog($stack, $required);
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    private function info(ResourcePackArchive $archive, bool $required, ?string $owner): ResourcePackInfo
    {
        return new ResourcePackInfo(
            $archive->id,
            $archive->version,
            $archive->name,
            $archive->size,
            $archive->subPackName,
            $required,
            $owner,
        );
    }
}
