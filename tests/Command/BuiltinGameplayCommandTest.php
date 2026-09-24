<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Command;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\Default\GamemodeCommand;
use Bedriox\Server\Command\Default\GiveCommand;
use Bedriox\Server\Command\Default\OnlinePlayerResolver;
use Bedriox\Server\Command\Default\TeleportCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuiltinGameplayCommandTest extends TestCase
{
    /** @return iterable<string, array{string, GameMode}> */
    public static function gameModes(): iterable
    {
        yield 'survival name' => ['survival', GameMode::SURVIVAL];
        yield 'survival number' => ['0', GameMode::SURVIVAL];
        yield 'creative name' => ['creative', GameMode::CREATIVE];
        yield 'creative number' => ['1', GameMode::CREATIVE];
        yield 'adventure name' => ['adventure', GameMode::ADVENTURE];
        yield 'adventure number' => ['2', GameMode::ADVENTURE];
        yield 'spectator name' => ['spectator', GameMode::SPECTATOR];
        yield 'spectator number' => ['3', GameMode::SPECTATOR];
    }

    #[DataProvider('gameModes')]
    public function testGamemodeAcceptsNamedAndNumericModes(string $argument, GameMode $expected): void
    {
        $player = $this->player('Target', $expected === GameMode::SURVIVAL ? GameMode::CREATIVE : GameMode::SURVIVAL);
        $changes = [];
        $command = new GamemodeCommand(
            new OnlinePlayerResolver(static fn(): array => [$player]),
            static function (Player $target, GameMode $mode) use (&$changes): bool {
                $changes[] = [$target->uuid, $mode];

                return true;
            },
        );
        $sender = new GameplayConsoleSender();

        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext(
            $sender,
            'gamemode',
            [$argument, 'target'],
        )));
        self::assertSame([[$player->uuid, $expected]], $changes);
        self::assertSame("Set Target's game mode to {$expected->value}.", $sender->messages[0]);
        self::assertSame('bedriox.command.gamemode', $command->definition()->permission);
    }

    public function testGamemodeDefaultsToPlayerSenderButRequiresConsoleTarget(): void
    {
        $player = $this->player('Self');
        $changes = [];
        $command = new GamemodeCommand(
            new OnlinePlayerResolver(static fn(): array => [$player]),
            static function (Player $target, GameMode $mode) use (&$changes): bool {
                $changes[] = [$target->uuid, $mode];

                return true;
            },
        );

        self::assertSame(CommandResult::USAGE, $command->execute(new CommandContext(
            new GameplayConsoleSender(),
            'gamemode',
            ['creative'],
        )));
        self::assertSame([], $changes);

        $sender = new GameplayPlayerSender($player);
        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext(
            $sender,
            'gamemode',
            ['creative'],
        )));
        self::assertSame([[$player->uuid, GameMode::CREATIVE]], $changes);
    }

    public function testGamemodeRejectsUnknownModesAndOfflinePlayersBeforeMutation(): void
    {
        $mutations = 0;
        $command = new GamemodeCommand(
            new OnlinePlayerResolver(static fn(): array => []),
            static function () use (&$mutations): bool {
                ++$mutations;

                return true;
            },
        );
        $sender = new GameplayConsoleSender();

        self::assertSame(CommandResult::FAILURE, $command->execute(new CommandContext(
            $sender,
            'gamemode',
            ['builder', 'Nobody'],
        )));
        self::assertSame('Unknown game mode.', array_pop($sender->messages));
        self::assertSame(CommandResult::FAILURE, $command->execute(new CommandContext(
            $sender,
            'gamemode',
            ['creative', 'Nobody'],
        )));
        self::assertSame('Player is not online.', array_pop($sender->messages));
        self::assertSame(0, $mutations);
    }

    public function testGamemodeReportsAnAlreadyActiveModeWithoutEnqueueingMutation(): void
    {
        $player = $this->player('Target', GameMode::CREATIVE);
        $mutations = 0;
        $command = new GamemodeCommand(
            new OnlinePlayerResolver(static fn(): array => [$player]),
            static function () use (&$mutations): bool {
                ++$mutations;

                return true;
            },
        );
        $sender = new GameplayConsoleSender();

        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext(
            $sender,
            'gamemode',
            ['creative', 'Target'],
        )));
        self::assertSame(0, $mutations);
        self::assertSame('Target is already in creative mode.', $sender->messages[0]);
    }

    public function testGiveCanonicalizesItemsAndUsesDefaultOrExplicitAmounts(): void
    {
        $player = $this->player('Target');
        $grants = [];
        $validated = [];
        $command = new GiveCommand(
            new OnlinePlayerResolver(static fn(): array => [$player]),
            static function (Player $target, string $identifier, int $amount) use (&$grants): bool {
                $grants[] = [$target->uuid, $identifier, $amount];

                return true;
            },
            static function (string $identifier) use (&$validated): bool {
                $validated[] = $identifier;

                return $identifier === 'minecraft:diamond';
            },
        );
        $sender = new GameplayConsoleSender();

        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext(
            $sender,
            'give',
            ['target', 'DIAMOND'],
        )));
        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext(
            $sender,
            'give',
            ['Target', 'minecraft:diamond', '64'],
        )));
        self::assertSame(['minecraft:diamond', 'minecraft:diamond'], $validated);
        self::assertSame([
            [$player->uuid, 'minecraft:diamond', 1],
            [$player->uuid, 'minecraft:diamond', 64],
        ], $grants);
        self::assertSame('bedriox.command.give', $command->definition()->permission);
    }

    public function testGiveRejectsUnknownItemsInvalidAmountsAndOfflinePlayers(): void
    {
        $player = $this->player('Target');
        $grants = 0;
        $command = new GiveCommand(
            new OnlinePlayerResolver(static fn(): array => [$player]),
            static function () use (&$grants): bool {
                ++$grants;

                return true;
            },
            static fn(string $identifier): bool => $identifier === 'minecraft:diamond',
        );
        $sender = new GameplayConsoleSender();

        self::assertSame(CommandResult::FAILURE, $command->execute(new CommandContext(
            $sender,
            'give',
            ['Missing', 'diamond'],
        )));
        self::assertSame('Player is not online.', array_pop($sender->messages));
        self::assertSame(CommandResult::FAILURE, $command->execute(new CommandContext(
            $sender,
            'give',
            ['Target', 'not_real'],
        )));
        self::assertSame('Unknown item.', array_pop($sender->messages));
        foreach (['0', '-1', '1.5', '32768'] as $amount) {
            self::assertSame(CommandResult::FAILURE, $command->execute(new CommandContext(
                $sender,
                'give',
                ['Target', 'diamond', $amount],
            )));
            $message = array_pop($sender->messages);
            self::assertIsString($message);
            self::assertStringStartsWith('Amount must be a whole number', $message);
        }
        self::assertSame(0, $grants);
    }

    public function testTeleportSupportsPlayerTargetsCoordinatesRotationAndRelativeValues(): void
    {
        $subject = $this->player('Subject');
        $destination = new Player(
            'Destination',
            '00000000-0000-0000-0000-000000000002',
            new Position(50.0, 80.0, -10.0),
            135.0,
            -20.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
        $teleports = [];
        $command = new TeleportCommand(
            new OnlinePlayerResolver(static fn(): array => [$subject, $destination]),
            static function (Player $player, Position $position, ?float $yaw, ?float $pitch) use (&$teleports): bool {
                $teleports[] = [$player->name, $position, $yaw, $pitch];

                return true;
            },
        );
        $sender = new GameplayPlayerSender($subject);

        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext($sender, 'tp', ['Destination'])));
        self::assertSame(50.0, $teleports[0][1]->x);
        self::assertSame(135.0, $teleports[0][2]);
        self::assertSame(-20.0, $teleports[0][3]);

        self::assertSame(CommandResult::SUCCESS, $command->execute(new CommandContext(
            $sender,
            'teleport',
            ['~2.5', '~', '~-3', '90', '30'],
        )));
        self::assertSame(2.5, $teleports[1][1]->x);
        self::assertSame(64.0, $teleports[1][1]->y);
        self::assertSame(-3.0, $teleports[1][1]->z);
        self::assertSame(90.0, $teleports[1][2]);
        self::assertSame(30.0, $teleports[1][3]);
    }

    public function testTeleportRequiresOtherPermissionAndRejectsInvalidCoordinates(): void
    {
        $subject = $this->player('Subject');
        $target = new Player(
            'Target',
            '00000000-0000-0000-0000-000000000002',
            new Position(1.0, 64.0, 1.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
        $calls = 0;
        $command = new TeleportCommand(
            new OnlinePlayerResolver(static fn(): array => [$subject, $target]),
            static function () use (&$calls): bool {
                ++$calls;

                return true;
            },
        );
        $sender = new GameplayPlayerSender($subject, []);

        self::assertSame(CommandResult::FAILURE, $command->execute(new CommandContext(
            $sender,
            'tp',
            ['Target', '0', '70', '0'],
        )));
        self::assertSame(0, $calls);

        self::assertSame(CommandResult::FAILURE, $command->execute(new CommandContext(
            new GameplayPlayerSender($subject),
            'tp',
            ['NaN', '64', '0'],
        )));
        self::assertSame(0, $calls);
    }

    private function player(string $name, GameMode $gameMode = GameMode::SURVIVAL): Player
    {
        return new Player(
            $name,
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
            gameMode: $gameMode,
        );
    }
}

class GameplayConsoleSender implements CommandSender
{
    /** @var list<string> */
    public array $messages = [];

    public function type(): CommandSenderType
    {
        return CommandSenderType::CONSOLE;
    }

    public function name(): string
    {
        return 'Console';
    }

    public function sendMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }
}

final class GameplayPlayerSender extends GameplayConsoleSender implements PlayerCommandSender
{
    /** @param list<string>|null $permissions */
    public function __construct(private readonly Player $player, private readonly ?array $permissions = null) {}

    public function type(): CommandSenderType
    {
        return CommandSenderType::PLAYER;
    }

    public function name(): string
    {
        return $this->player->name;
    }

    public function player(): Player
    {
        return $this->player;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions === null || in_array($permission, $this->permissions, true);
    }
}
