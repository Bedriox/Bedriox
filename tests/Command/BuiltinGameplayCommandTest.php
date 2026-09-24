<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Command;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\CommandSoftEnum;
use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\Default\GamemodeCommand;
use Bedriox\Server\Command\Default\GiveCommand;
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
        $command = new GamemodeCommand(static function (Player $target, GameMode $mode) use (&$changes): bool {
            $changes[] = [$target->uuid, $mode];

            return true;
        });

        $result = $command->execute(new CommandContext(
            new GameplayConsoleSender(),
            'gamemode',
            new CommandValues(['mode' => $argument, 'player' => $player]),
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame("Set Target's game mode to {$expected->value}.", $result->message());
        self::assertSame([[$player->uuid, $expected]], $changes);
        self::assertSame('bedriox.command.gamemode', $command->definition()->permission);
        self::assertSame(
            '/gamemode <mode:survival|creative|adventure|spectator|0|1|2|3|s|c|a|sp> [player]',
            $command->defineArguments()->usage('gamemode')[0],
        );
    }

    public function testGamemodeDefaultsToPlayerSenderButRequiresConsoleTarget(): void
    {
        $player = $this->player('Self');
        $changes = [];
        $command = new GamemodeCommand(static function (Player $target, GameMode $mode) use (&$changes): bool {
            $changes[] = [$target->uuid, $mode];

            return true;
        });

        $consoleResult = $command->execute(new CommandContext(
            new GameplayConsoleSender(),
            'gamemode',
            new CommandValues(['mode' => 'creative']),
        ));
        self::assertFalse($consoleResult->isSuccess());
        self::assertSame('A player target is required when running this command from the console.', $consoleResult->message());
        self::assertSame([], $changes);

        $playerResult = $command->execute(new CommandContext(
            new GameplayPlayerSender($player),
            'gamemode',
            new CommandValues(['mode' => 'creative']),
        ));
        self::assertTrue($playerResult->isSuccess());
        self::assertSame([[$player->uuid, GameMode::CREATIVE]], $changes);
    }

    public function testGamemodeReportsAnAlreadyActiveModeWithoutMutation(): void
    {
        $player = $this->player('Target', GameMode::CREATIVE);
        $mutations = 0;
        $command = new GamemodeCommand(static function () use (&$mutations): bool {
            ++$mutations;

            return true;
        });

        $result = $command->execute(new CommandContext(
            new GameplayConsoleSender(),
            'gamemode',
            new CommandValues(['mode' => 'creative', 'player' => $player]),
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $mutations);
        self::assertSame('Target is already in creative mode.', $result->message());
    }

    public function testGiveUsesTypedPlayerAndDefaultOrExplicitAmounts(): void
    {
        $player = $this->player('Target');
        $grants = [];
        $validated = [];
        $command = new GiveCommand(
            $this->itemEnum(['minecraft:diamond']),
            static function (Player $target, string $identifier, int $amount) use (&$grants): bool {
                $grants[] = [$target->uuid, $identifier, $amount];

                return true;
            },
            static function (string $identifier) use (&$validated): bool {
                $validated[] = $identifier;

                return $identifier === 'minecraft:diamond';
            },
        );

        foreach ([1, 64] as $amount) {
            $result = $command->execute(new CommandContext(
                new GameplayConsoleSender(),
                'give',
                new CommandValues(['player' => $player, 'item' => $amount === 1 ? 'DIAMOND' : 'minecraft:diamond', 'amount' => $amount]),
            ));
            self::assertTrue($result->isSuccess());
        }

        self::assertSame(['minecraft:diamond', 'minecraft:diamond'], $validated);
        self::assertSame([
            [$player->uuid, 'minecraft:diamond', 1],
            [$player->uuid, 'minecraft:diamond', 64],
        ], $grants);
        self::assertSame('bedriox.command.give', $command->definition()->permission);
        self::assertSame('/give <player> <item> [amount]', $command->defineArguments()->usage('give')[0]);
    }

    public function testGiveRejectsUnknownItemsBeforeMutation(): void
    {
        $player = $this->player('Target');
        $grants = 0;
        $command = new GiveCommand(
            $this->itemEnum(['minecraft:diamond']),
            static function () use (&$grants): bool {
                ++$grants;

                return true;
            },
            static fn(string $identifier): bool => $identifier === 'minecraft:diamond',
        );

        $result = $command->execute(new CommandContext(
            new GameplayConsoleSender(),
            'give',
            new CommandValues(['player' => $player, 'item' => 'not_real', 'amount' => 1]),
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('Unknown item.', $result->message());
        self::assertSame(0, $grants);
    }

    public function testTeleportSupportsPlayerTargetsCoordinatesAndRotation(): void
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
            static function (Player $player, Position $position, ?float $yaw, ?float $pitch) use (&$teleports): bool {
                $teleports[] = [$player->name, $position, $yaw, $pitch];

                return true;
            },
        );
        $sender = new GameplayPlayerSender($subject);

        $toPlayer = $command->execute(new CommandContext(
            $sender,
            'tp',
            new CommandValues(['destinationPlayer' => $destination]),
        ));
        self::assertTrue($toPlayer->isSuccess());
        self::assertSame('Teleported Subject to Destination.', $toPlayer->message());
        self::assertSame(50.0, $teleports[0][1]->x);
        self::assertSame(135.0, $teleports[0][2]);
        self::assertSame(-20.0, $teleports[0][3]);

        $toPosition = $command->execute(new CommandContext(
            $sender,
            'teleport',
            new CommandValues([
                'destination' => new Position(2.5, 64.0, -3.0),
                'yaw' => 90.0,
                'pitch' => 30.0,
            ]),
        ));
        self::assertTrue($toPosition->isSuccess());
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
            static function () use (&$calls): bool {
                ++$calls;

                return true;
            },
        );
        $sender = new GameplayPlayerSender($subject, []);

        $permissionResult = $command->execute(new CommandContext(
            $sender,
            'tp',
            new CommandValues([
                'subject' => $target,
                'destination' => new Position(0.0, 70.0, 0.0),
            ]),
        ));
        self::assertFalse($permissionResult->isSuccess());
        self::assertSame('You do not have permission to teleport other players.', $permissionResult->message());
        self::assertSame(0, $calls);

        $boundsResult = $command->execute(new CommandContext(
            new GameplayPlayerSender($subject),
            'tp',
            new CommandValues(['destination' => new Position(30_000_001.0, 64.0, 0.0)]),
        ));
        self::assertFalse($boundsResult->isSuccess());
        self::assertSame('Coordinates are outside the supported world bounds.', $boundsResult->message());
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

    /** @param list<string> $values */
    private function itemEnum(array $values): CommandSoftEnum
    {
        return new class ($values) implements CommandSoftEnum {
            /** @param list<string> $values */
            public function __construct(private array $values) {}

            public function name(): string
            {
                return 'bedriox:item_identifiers';
            }

            public function values(): array
            {
                return $this->values;
            }

            public function replace(array $values): bool
            {
                if ($this->values === $values) {
                    return false;
                }
                $this->values = $values;

                return true;
            }

            public function add(string $value): bool
            {
                if (in_array($value, $this->values, true)) {
                    return false;
                }
                $this->values[] = $value;

                return true;
            }

            public function remove(string $value): bool
            {
                $index = array_search($value, $this->values, true);
                if ($index === false) {
                    return false;
                }
                array_splice($this->values, $index, 1);

                return true;
            }

            public function isRegistered(): bool
            {
                return true;
            }
        };
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
