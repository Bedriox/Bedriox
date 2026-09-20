<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Protocol\Packet\AvailableCommandsPacket;
use Bedriox\Protocol\Packet\CommandArgumentType;
use Bedriox\Protocol\Packet\CommandDefinition as ProtocolCommandDefinition;
use Bedriox\Protocol\Packet\CommandOverload;
use Bedriox\Protocol\Packet\CommandParameter;
use Bedriox\Protocol\Packet\CommandPermission;
use Bedriox\Protocol\Packet\SetCommandsEnabledPacket;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\LoginChannelReady;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Plugin\Command\CommandRegistry;
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
        private ?CommandRegistry $commandRegistry = null,
        private ?PermissionStore $permissionStore = null,
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

        $initializationPackets = $this->initialization->create($ready->login, $runtimeEntityId, $bootstrap);
        if ($this->commandRegistry !== null && $this->permissionStore !== null) {
            $initializationPackets = array_values(array_filter(
                $initializationPackets,
                static fn($packet): bool => !$packet instanceof SetCommandsEnabledPacket,
            ));
            $definitions = $this->commandRegistry->availableDefinitions(
                CommandSenderType::PLAYER,
                fn(string $permission): bool => $this->permissionStore->hasPermission($ready->login->identity, $permission),
            );
            $commands = array_map(
                static fn($definition): ProtocolCommandDefinition => new ProtocolCommandDefinition(
                    $definition->name,
                    $definition->description,
                    CommandPermission::Any,
                    [new CommandOverload([new CommandParameter('args', CommandArgumentType::RawText, true)])],
                ),
                $definitions,
            );
            $initializationPackets[] = new SetCommandsEnabledPacket(true);
            $initializationPackets[] = new AvailableCommandsPacket($commands);
        }

        return new BedrockPlayChannel(
            $ready,
            $sessionId,
            $runtimeEntityId,
            $initializationPackets,
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
