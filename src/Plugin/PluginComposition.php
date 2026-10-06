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

use Bedriox\Api\Update\UpdateService;
use Bedriox\Server\Runtime\BootstrappedServer;

/** @internal Mutable startup hand-off; never exposed to plugins. */
final class PluginComposition
{
    public ?BootstrappedServer $server = null;
    public ?PluginHost $host = null;
    public ?PluginItemBehaviorRegistrar $itemBehaviors = null;
    public ?PluginRecipeRegistrar $recipes = null;
    public ?PluginEntityLifecycleBridge $entityLifecycle = null;
    public ?UpdateService $updates = null;
}
