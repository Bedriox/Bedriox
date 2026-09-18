<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Simulation\SimulationPluginApiBackend;

final readonly class BootstrappedServer
{
    public function __construct(
        public ServerRuntime $runtime,
        public string $localAddress,
        public int $localPort,
        public ?string $securityWarning,
        public SimulationPluginApiBackend $pluginApi,
    ) {}
}
