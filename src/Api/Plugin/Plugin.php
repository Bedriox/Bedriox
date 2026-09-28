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

namespace Bedriox\Api\Plugin;

abstract class Plugin
{
    final public function __construct(private readonly PluginContext $context) {}

    final public function context(): PluginContext
    {
        return $this->context;
    }

    final public function logger(): PluginLogger
    {
        return $this->context->logger();
    }

    public function onLoad(): void {}

    public function onEnable(): void {}

    public function onDisable(): void {}
}
