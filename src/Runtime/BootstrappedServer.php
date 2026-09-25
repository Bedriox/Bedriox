<?php

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
    ) {}
}
