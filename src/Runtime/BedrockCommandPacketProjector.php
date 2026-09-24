<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Command\CommandParameter as ApiCommandParameter;
use Bedriox\Api\Command\CommandParameterType;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Bedriox\Protocol\Packet\AvailableCommandsPacket;
use Bedriox\Protocol\Packet\CommandArgumentType;
use Bedriox\Protocol\Packet\CommandDefinition as ProtocolCommandDefinition;
use Bedriox\Protocol\Packet\CommandEnum;
use Bedriox\Protocol\Packet\CommandOverload;
use Bedriox\Protocol\Packet\CommandParameter;
use Bedriox\Protocol\Packet\CommandPermission;
use Bedriox\Protocol\Packet\SoftEnumUpdateType;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\Packet\UpdateSoftEnumPacket;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Plugin\Command\CommandRegistry;

/** Projects server-owned command authority into the current Bedrock packet model. */
final readonly class BedrockCommandPacketProjector
{
    public const string ONLINE_PLAYERS_ENUM = 'bedriox:online_players';

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

    /** @param list<Player> $onlinePlayers */
    public function availableCommands(string $uuid, array $onlinePlayers = []): AvailableCommandsPacket
    {
        $commands = $this->commands->availableCommands(
            CommandSenderType::PLAYER,
            fn(string $permission): bool => $this->permissions->hasPermission($uuid, $permission),
        );
        $onlinePlayerEnum = new CommandEnum(self::ONLINE_PLAYERS_ENUM, self::onlinePlayerNames($onlinePlayers), true);

        return new AvailableCommandsPacket(array_map(
            static fn($registered): ProtocolCommandDefinition => new ProtocolCommandDefinition(
                $registered->definition->name,
                $registered->definition->description,
                CommandPermission::Any,
                array_map(
                    static fn($overload): CommandOverload => new CommandOverload(array_map(
                        static fn(ApiCommandParameter $parameter): CommandParameter => new CommandParameter(
                            $parameter->name(),
                            self::protocolType($registered->definition->name, $parameter, $onlinePlayerEnum),
                            $parameter->isOptional(),
                        ),
                        $overload->parameters(),
                    )),
                    $registered->arguments->overloads(),
                ),
                aliases: self::wireAliases(
                    $registered->definition->name,
                    $registered->definition->aliases,
                ),
            ),
            $commands,
        ));
    }

    /** @param list<Player> $onlinePlayers */
    public function onlinePlayerUpdate(array $onlinePlayers): UpdateSoftEnumPacket
    {
        return new UpdateSoftEnumPacket(
            self::ONLINE_PLAYERS_ENUM,
            self::onlinePlayerNames($onlinePlayers),
            SoftEnumUpdateType::Replace,
        );
    }

    /** @param list<string> $values */
    public function softEnumUpdate(string $name, array $values): UpdateSoftEnumPacket
    {
        return new UpdateSoftEnumPacket($name, $values, SoftEnumUpdateType::Replace);
    }

    private static function protocolType(
        string $command,
        ApiCommandParameter $parameter,
        CommandEnum $onlinePlayers,
    ): CommandArgumentType|CommandEnum {
        return match ($parameter->type()) {
            CommandParameterType::STRING => CommandArgumentType::String,
            CommandParameterType::INTEGER => CommandArgumentType::Integer,
            CommandParameterType::FLOAT => CommandArgumentType::Float,
            CommandParameterType::BOOLEAN => new CommandEnum('bedriox:boolean', ['true', 'false']),
            CommandParameterType::ONLINE_PLAYER => $onlinePlayers,
            CommandParameterType::PLAYERS => CommandArgumentType::Target,
            CommandParameterType::CHOICE, CommandParameterType::ENUM => new CommandEnum(
                self::enumName($command, $parameter),
                $parameter->choices(),
            ),
            CommandParameterType::SOFT_ENUM => self::protocolSoftEnum($parameter),
            CommandParameterType::POSITION => CommandArgumentType::Position,
            CommandParameterType::BLOCK_POSITION => CommandArgumentType::BlockPosition,
            CommandParameterType::MESSAGE => CommandArgumentType::Message,
            CommandParameterType::JSON => CommandArgumentType::Json,
            CommandParameterType::RAW_TEXT => CommandArgumentType::RawText,
            CommandParameterType::LITERAL => new CommandEnum(
                self::enumName($command, $parameter, 'literal'),
                [$parameter->literalValue() ?? $parameter->name()],
            ),
        };
    }

    private static function enumName(
        string $command,
        ApiCommandParameter $parameter,
        string $kind = 'parameter',
    ): string {
        $fingerprint = substr(hash('sha256', implode("\0", $parameter->choices())), 0, 12);
        $scope = $kind === 'parameter'
            ? "{$command}:{$parameter->name()}"
            : "{$command}:{$kind}:{$parameter->name()}";

        return "bedriox:command:{$scope}:{$fingerprint}";
    }

    private static function protocolSoftEnum(ApiCommandParameter $parameter): CommandEnum
    {
        $softEnum = $parameter->softEnumValue()
            ?? throw new \LogicException('A command soft-enum parameter is missing its registered value source.');

        return new CommandEnum($softEnum->name(), $softEnum->values(), true);
    }

    /**
     * Bedrock hides a command's primary name when its alias enum omits that name.
     *
     * @param list<string> $aliases
     * @return list<string>
     */
    private static function wireAliases(string $name, array $aliases): array
    {
        return $aliases === [] ? [] : [$name, ...$aliases];
    }

    /**
     * @param list<Player> $players
     * @return list<string>
     */
    private static function onlinePlayerNames(array $players): array
    {
        $names = [];
        foreach ($players as $player) {
            if (!$player->isConnected()) {
                continue;
            }
            $names[strtolower($player->name)] = $player->name;
        }
        natcasesort($names);

        return array_values($names);
    }
}
