<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\LoginChannelReady;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\World\World;

final readonly class BedrockPlayChannelFactory implements PlayChannelFactory
{
    public function __construct(
        private PlayInitializationFactory $initialization,
        private SimulationCommandFactory $commands = new SimulationCommandFactory(),
        private RuntimeLimits $limits = new RuntimeLimits(),
        private ?RuntimeDiagnostics $diagnostics = null,
        private ?World $world = null,
        private ?BedrockChunkPacketSerializer $chunkSerializer = null,
        private int $viewDistance = 1,
        private int $spawnRadius = 1,
        private int $chunksGeneratePerTick = 1,
        private int $chunksSendPerTick = 1,
        private ?BedrockInventoryPacketProjector $inventoryProjector = null,
    ) {}

    public function create(LoginChannelReady $ready, string $sessionId, UnsignedLong $runtimeEntityId, ?PlayerBootstrap $bootstrap = null): BedrockPlayChannel
    {
        $spawnX = 0.0;
        $spawnY = 64.0;
        $spawnZ = 0.0;
        if ($this->world !== null) {
            $spawn = $this->world->spawn();
            $spawnX = (float) $spawn->x;
            $spawnY = (float) $spawn->y;
            $spawnZ = (float) $spawn->z;
        }
        if ($bootstrap !== null) {
            $spawnX = $bootstrap->position->x;
            $spawnY = $bootstrap->position->y;
            $spawnZ = $bootstrap->position->z;
        }

        return new BedrockPlayChannel(
            $ready,
            $sessionId,
            $runtimeEntityId,
            $this->initialization->create($ready->login, $runtimeEntityId, $bootstrap),
            $this->initialization->fixedFlatRuntimeIds(),
            $this->commands,
            $this->limits,
            $this->diagnostics,
            $this->world,
            $this->chunkSerializer,
            $this->viewDistance,
            $this->spawnRadius,
            $this->chunksGeneratePerTick,
            $this->chunksSendPerTick,
            $spawnX,
            $spawnY,
            $spawnZ,
            $this->inventoryProjector,
        );
    }
}
