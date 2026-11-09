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

use Bedriox\Api\ResourcePack\ResourcePackDefinition;
use Bedriox\Api\ResourcePack\ResourcePackInfo;
use Bedriox\Api\ResourcePack\ResourcePackManager;
use Bedriox\Server\ResourcePack\ResourcePackRegistry;

final readonly class OwnedResourcePackManager implements ResourcePackManager
{
    public function __construct(
        private string $owner,
        private ResourcePackRegistry $registry,
        private PluginOwnershipRegistry $ownership,
    ) {}

    public function register(ResourcePackDefinition $definition): ResourcePackInfo
    {
        $info = $this->registry->register($this->owner, $definition);
        $resourceId = 'resource-pack:' . strtolower($info->uuid);
        try {
            $this->ownership->own(
                $this->owner,
                $resourceId,
                fn() => $this->registry->removeIfOwned($this->owner, $info->uuid),
            );
        } catch (\Throwable $failure) {
            $this->registry->removeIfOwned($this->owner, $info->uuid);
            throw $failure;
        }

        return $info;
    }

    public function remove(string $uuid): void
    {
        $this->registry->remove($this->owner, $uuid);
        $this->ownership->forget($this->owner, 'resource-pack:' . strtolower($uuid));
    }

    public function all(): array
    {
        return $this->registry->all();
    }
}
