<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Command\AllowedCommandSenders;
use Bedriox\Api\Command\Command;
use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\AvailableCommandsPacket;
use Bedriox\Protocol\Packet\CommandArgumentType;
use Bedriox\Protocol\Packet\CommandEnum;
use Bedriox\Protocol\Packet\CommandParameter as ProtocolCommandParameter;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\SoftEnumUpdateType;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Runtime\BedrockCommandPacketProjector;
use PHPUnit\Framework\TestCase;
use Throwable;

final class BedrockCommandPacketProjectorTest extends TestCase
{
    private const string UUID = '00000000-0000-0000-0000-000000000001';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
    }

    public function testProjectsOverloadsStandardTypesEnumsAliasesAndConnectedPlayers(): void
    {
        [$registry, $permissions] = $this->dependencies();
        $registry->register('Tools', new ProjectorCommand(
            new CommandDefinition('shape', 'Exercise command shapes', ['form']),
            CommandArguments::create()
                ->addOverload(CommandOverload::create()
                    ->addArgument(CommandParameter::literal('basic'))
                    ->addArgument(CommandParameter::string('name'))
                    ->addArgument(CommandParameter::integer('count'))
                    ->addArgument(CommandParameter::float('ratio'))
                    ->addArgument(CommandParameter::boolean('enabled'))
                    ->addArgument(CommandParameter::choice('mode', ['safe', 'fast']))
                    ->addArgument(CommandParameter::players('targets'))
                    ->addArgument(CommandParameter::entity('entity'))
                    ->addArgument(CommandParameter::entities('entities'))
                    ->addArgument(CommandParameter::position('position'))
                    ->addArgument(CommandParameter::blockPosition('block'))
                    ->addArgument(CommandParameter::message('message')->optional()))
                ->addOverload(CommandOverload::create()
                    ->addArgument(CommandParameter::literal('raw'))
                    ->addArgument(CommandParameter::choice('mode', ['compact', 'verbose']))
                    ->addArgument(CommandParameter::rawText('input')))
                ->addOverload(CommandOverload::create()
                    ->addArgument(CommandParameter::literal('json'))
                    ->addArgument(CommandParameter::json('data'))),
        ));
        $registry->register('Tools', new ProjectorCommand(
            new CommandDefinition('visit', 'Visit a player'),
            CommandArguments::create()->addArgument(CommandParameter::onlinePlayer('player')),
        ));
        $registry->register('Tools', new ProjectorCommand(
            new CommandDefinition('whisper', 'Whisper to a player'),
            CommandArguments::create()
                ->addArgument(CommandParameter::onlinePlayer('recipient'))
                ->addArgument(CommandParameter::message('message')),
        ));
        $players = [
            self::player('Zoe', true),
            self::player('Offline', false),
            self::player('alex', true),
        ];

        $projected = (new BedrockCommandPacketProjector($registry, $permissions))
            ->availableCommands(self::UUID, $players);
        $packet = AvailableCommandsPacket::decode($projected->encode());
        $commands = self::commandsByName($packet);

        self::assertSame(['shape', 'form'], $commands['shape']->aliases);
        self::assertSame([], $commands['visit']->aliases);
        self::assertCount(3, $commands['shape']->overloads);

        $basic = $commands['shape']->overloads[0]->parameters;
        self::assertEnum($basic[0], 'bedriox:command:shape:literal:basic', ['basic'], false);
        self::assertSame(CommandArgumentType::String, $basic[1]->type);
        self::assertSame(CommandArgumentType::Integer, $basic[2]->type);
        self::assertSame(CommandArgumentType::Float, $basic[3]->type);
        self::assertEnum($basic[4], 'bedriox:boolean', ['true', 'false'], false);
        self::assertEnum($basic[5], 'bedriox:command:shape:mode', ['safe', 'fast'], false);
        self::assertSame(CommandArgumentType::Target, $basic[6]->type);
        self::assertSame(CommandArgumentType::Target, $basic[7]->type);
        self::assertSame(CommandArgumentType::Target, $basic[8]->type);
        self::assertSame(CommandArgumentType::Position, $basic[9]->type);
        self::assertSame(CommandArgumentType::BlockPosition, $basic[10]->type);
        self::assertSame(CommandArgumentType::Message, $basic[11]->type);
        self::assertTrue($basic[11]->optional);

        $raw = $commands['shape']->overloads[1]->parameters;
        self::assertEnum($raw[0], 'bedriox:command:shape:literal:raw', ['raw'], false);
        self::assertEnum($raw[1], 'bedriox:command:shape:mode', ['compact', 'verbose'], false);
        self::assertSame(CommandArgumentType::RawText, $raw[2]->type);

        $json = $commands['shape']->overloads[2]->parameters;
        self::assertEnum($json[0], 'bedriox:command:shape:literal:json', ['json'], false);
        self::assertSame(CommandArgumentType::Json, $json[1]->type);

        $visitPlayer = $commands['visit']->overloads[0]->parameters[0]->type;
        $whisperPlayer = $commands['whisper']->overloads[0]->parameters[0]->type;
        self::assertInstanceOf(CommandEnum::class, $visitPlayer);
        self::assertSame($visitPlayer, $whisperPlayer);
        self::assertSame(BedrockCommandPacketProjector::ONLINE_PLAYERS_ENUM, $visitPlayer->name);
        self::assertSame(['alex', 'Zoe'], $visitPlayer->values);
        self::assertTrue($visitPlayer->soft);
    }

    public function testPermissionAndSenderPoliciesFilterProjectedCommands(): void
    {
        [$registry, $permissions] = $this->dependencies();
        $registry->register('Tools', new ProjectorCommand(new CommandDefinition('public', 'Public command')));
        $registry->register('Tools', new ProjectorCommand(new CommandDefinition(
            'protected',
            'Protected command',
            permission: 'tools.protected',
        )));
        $registry->register('Tools', new ProjectorCommand(new CommandDefinition(
            'console',
            'Console command',
            allowedSenders: AllowedCommandSenders::CONSOLE_ONLY,
        )));
        $projector = new BedrockCommandPacketProjector($registry, $permissions);

        self::assertSame(
            ['public'],
            array_column($projector->availableCommands(self::UUID)->commands, 'name'),
        );

        $permissions->grant(self::UUID, 'Alex', 'tools.protected');
        self::assertSame(
            ['public', 'protected'],
            array_column($projector->availableCommands(self::UUID)->commands, 'name'),
        );
    }

    public function testOnlinePlayerUpdateReplacesTheSharedEnumWithConnectedNames(): void
    {
        [$registry, $permissions] = $this->dependencies();
        $update = (new BedrockCommandPacketProjector($registry, $permissions))->onlinePlayerUpdate([
            self::player('zoe', true),
            self::player('Offline', false),
            self::player('Alex', true),
        ]);

        self::assertSame(BedrockCommandPacketProjector::ONLINE_PLAYERS_ENUM, $update->enumName);
        self::assertSame(['Alex', 'zoe'], $update->values);
        self::assertSame(SoftEnumUpdateType::Replace, $update->type);
    }

    public function testProjectsAndUpdatesRegisteredDynamicSoftEnums(): void
    {
        [$registry, $permissions] = $this->dependencies();
        $kits = $registry->registerSoftEnum('Tools', 'kits', ['starter', 'builder']);
        $registry->register('Tools', new ProjectorCommand(
            new CommandDefinition('kit', 'Select a kit'),
            CommandArguments::create()->addArgument(CommandParameter::softEnum('kit', $kits)),
        ));
        $projector = new BedrockCommandPacketProjector($registry, $permissions);

        $commands = self::commandsByName(AvailableCommandsPacket::decode(
            $projector->availableCommands(self::UUID)->encode(),
        ));
        self::assertEnum(
            $commands['kit']->overloads[0]->parameters[0],
            'bedriox:plugin:tools:kits',
            ['starter', 'builder'],
            true,
        );

        $kits->replace(['starter', 'builder', 'vip']);
        $update = $registry->drainSoftEnumUpdates()[0];
        $packet = $projector->softEnumUpdate($update->name, $update->values);
        self::assertSame('bedriox:plugin:tools:kits', $packet->enumName);
        self::assertSame(['starter', 'builder', 'vip'], $packet->values);
        self::assertSame(SoftEnumUpdateType::Replace, $packet->type);
    }

    /** @return array{CommandRegistry, PermissionStore} */
    private function dependencies(): array
    {
        $plugins = new ProjectorPluginControl();
        $ownership = new PluginOwnershipRegistry();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);
        $path = sys_get_temp_dir() . '/bedriox-command-projector-' . bin2hex(random_bytes(8)) . '.json';
        $this->temporaryFiles[] = $path;

        return [
            new CommandRegistry($plugins, $execution, $actions, $ownership, $events),
            new PermissionStore($path),
        ];
    }

    /** @return array<string, \Bedriox\Protocol\Packet\CommandDefinition> */
    private static function commandsByName(AvailableCommandsPacket $packet): array
    {
        $commands = [];
        foreach ($packet->commands as $command) {
            $commands[$command->name] = $command;
        }

        return $commands;
    }

    /**
     * @param non-empty-string $name
     * @param list<string>     $values
     */
    private static function assertEnum(
        ProtocolCommandParameter $parameter,
        string $name,
        array $values,
        bool $soft,
    ): void {
        self::assertInstanceOf(CommandEnum::class, $parameter->type);
        self::assertStringStartsWith($name, $parameter->type->name);
        self::assertSame($values, $parameter->type->values);
        self::assertSame($soft, $parameter->type->soft);
    }

    private static function player(string $name, bool $connected): Player
    {
        return new Player(
            $name,
            $connected
                ? '00000000-0000-0000-0000-' . ($name === 'Zoe' || $name === 'zoe' ? '000000000002' : '000000000003')
                : '00000000-0000-0000-0000-000000000004',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
            playerConnection: new PlayerConnection(
                static fn(): bool => $connected,
                static fn(Packet $packet, bool $immediate): bool => true,
            ),
        );
    }
}

final readonly class ProjectorCommand implements Command
{
    public function __construct(
        private CommandDefinition $commandDefinition,
        private ?CommandArguments $arguments = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return $this->commandDefinition;
    }

    public function defineArguments(): CommandArguments
    {
        return $this->arguments ?? CommandArguments::none();
    }

    public function execute(CommandContext $context): CommandResult
    {
        return CommandResult::success();
    }
}

final class ProjectorPluginControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return true;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
