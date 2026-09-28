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

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Simulation\SimulationPluginApiBackend;
use Bedriox\Server\World\World;

final readonly class BootstrappedServer
{
    public function __construct(
        public ServerRuntime $runtime,
        public string $localAddress,
        public int $localPort,
        public ?string $securityWarning,
        public SimulationPluginApiBackend $pluginApi,
        public World $world,
        public CraftingCatalog $craftingCatalog,
        public RuntimeWorldManager $worldManager,
    ) {}
}
