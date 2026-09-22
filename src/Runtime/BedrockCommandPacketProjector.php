<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Player\GameMode;
use Bedriox\Protocol\Packet\AvailableCommandsPacket;
use Bedriox\Protocol\Packet\CommandArgumentType;
use Bedriox\Protocol\Packet\CommandDefinition as ProtocolCommandDefinition;
use Bedriox\Protocol\Packet\CommandOverload;
use Bedriox\Protocol\Packet\CommandParameter;
use Bedriox\Protocol\Packet\CommandPermission;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Plugin\Command\CommandRegistry;

/** Projects server-owned command authority into the current Bedrock packet model. */
final readonly class BedrockCommandPacketProjector
{
    public function __construct(
        private CommandRegistry $commands,
        private PermissionStore $permissions,
    ) {}

    public function abilities(
        string $uuid,
        int $runtimeEntityId,
        GameMode $gameMode = GameMode::SURVIVAL,
    ): UpdateAbilitiesPacket {
        return (new GameModePacketProjector())->abilities(
            $gameMode,
            $runtimeEntityId,
            $this->permissions->isOperator($uuid),
        );
    }

    public function availableCommands(string $uuid): AvailableCommandsPacket
    {
        $definitions = $this->commands->availableDefinitions(
            CommandSenderType::PLAYER,
            fn(string $permission): bool => $this->permissions->hasPermission($uuid, $permission),
        );

        return new AvailableCommandsPacket(array_map(
            static fn($definition): ProtocolCommandDefinition => new ProtocolCommandDefinition(
                $definition->name,
                $definition->description,
                CommandPermission::Any,
                [new CommandOverload([new CommandParameter('args', CommandArgumentType::RawText, true)])],
            ),
            $definitions,
        ));
    }
}
