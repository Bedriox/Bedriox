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

use Bedriox\Api\Plugin\SourcePluginDefinition;
use Bedriox\Api\Plugin\SourcePluginRegistrar;
use Closure;

final readonly class OwnedSourcePluginRegistrar implements SourcePluginRegistrar
{
    /** @param Closure(string, list<SourcePluginDefinition>): void $register */
    public function __construct(
        private string $plugin,
        private string $pluginsDirectory,
        private Closure $register,
    ) {}

    public function pluginsDirectory(): string
    {
        return $this->pluginsDirectory;
    }

    public function register(array $definitions): void
    {
        ($this->register)($this->plugin, $definitions);
    }
}
