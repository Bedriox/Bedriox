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
use Bedriox\Server\Plugin\Data\PluginResourceProvider;
use Closure;

final readonly class PluginPackage
{
    /**
     * @param Closure(string): void $autoloader
     * @param Closure(PluginContext): Plugin $instantiate
     */
    public function __construct(
        public string $archive,
        public PluginManifest $manifest,
        public Closure $autoloader,
        public Closure $instantiate,
        public PluginResourceProvider $resources,
        public ?PluginArchiveIdentity $archiveIdentity = null,
    ) {}
}
