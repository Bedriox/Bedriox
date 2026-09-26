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
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\BuiltinCommandRegistrar;
use Bedriox\Server\Command\Default\SummonCommand;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SummonCommandTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory !== null) {
            $files = glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            if (is_dir($this->temporaryDirectory)) {
                rmdir($this->temporaryDirectory);
            }
        }
        $this->temporaryDirectory = null;
    }

    public function testDefinitionUsesOperatorPermissionAndTypedOptionalPosition(): void
    {
        $command = new SummonCommand(self::entityTypes(['cow', 'example:guardian']));

        self::assertSame('summon', $command->definition()->name);
        self::assertSame('bedriox.command.summon', $command->definition()->permission);
        self::assertSame(['/summon <type> [position]'], $command->defineArguments()->usage('summon'));
        $parameters = $command->defineArguments()->overloads()[0]->parameters();
        self::assertSame('bedriox:entity_identifiers', $parameters[0]->softEnumValue()?->name());
        self::assertTrue($parameters[1]->isOptional());
    }

    public function testPlayerPositionIsUsedWhenCoordinatesAreOmitted(): void
    {
        $player = self::player(new Position(12.5, 70.0, -3.25));
        $calls = [];
        $command = new SummonCommand(
            self::entityTypes(['cow']),
            static function (string $type, Position $position, ?Player $source) use (&$calls): bool {
                $calls[] = [$type, $position, $source?->uuid];

                return true;
            },
        );

        $result = $command->execute(new CommandContext(
            new SummonPlayerSender($player),
            'summon',
            new CommandValues(['type' => 'cow']),
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame('Summoned minecraft:cow at 12.50, 70.00, -3.25.', $result->message());
        self::assertSame('minecraft:cow', $calls[0][0]);
        self::assertSame($player->position, $calls[0][1]);
        self::assertSame($player->uuid, $calls[0][2]);
    }

    public function testConsoleRequiresCoordinatesAndExplicitCoordinatesHaveNoPlayerSource(): void
    {
        $calls = [];
        $command = new SummonCommand(
            self::entityTypes(['cow']),
            static function (string $type, Position $position, ?Player $source) use (&$calls): bool {
                $calls[] = [$type, $position, $source];

                return true;
            },
        );
        $sender = new SummonConsoleSender();

        $missing = $command->execute(new CommandContext(
            $sender,
            'summon',
            new CommandValues(['type' => 'cow']),
        ));
        self::assertFalse($missing->isSuccess());
        self::assertSame('Coordinates are required when running this command from the console.', $missing->message());
        self::assertSame([], $calls);

        $position = new Position(1.25, 64.0, -8.5);
        $explicit = $command->execute(new CommandContext(
            $sender,
            'summon',
            new CommandValues(['type' => 'cow', 'position' => $position]),
        ));
        self::assertTrue($explicit->isSuccess());
        self::assertSame([['minecraft:cow', $position, null]], $calls);
    }

    public function testUnknownAndOutOfWorldRequestsNeverReachAuthority(): void
    {
        $calls = 0;
        $command = new SummonCommand(
            self::entityTypes(['cow']),
            static function () use (&$calls): bool {
                ++$calls;

                return true;
            },
        );
        $sender = new SummonConsoleSender();

        $unknown = $command->execute(new CommandContext(
            $sender,
            'summon',
            new CommandValues(['type' => 'minecraft:not_real', 'position' => new Position(0.0, 64.0, 0.0)]),
        ));
        self::assertFalse($unknown->isSuccess());
        self::assertSame('Unknown or unavailable entity type.', $unknown->message());

        foreach ([
            new Position(INF, 64.0, 0.0),
            new Position(0.0, -65.0, 0.0),
            new Position(0.0, 320.0, 0.0),
            new Position(0.0, 64.0, 30_000_001.0),
        ] as $position) {
            $outOfWorld = $command->execute(new CommandContext(
                $sender,
                'summon',
                new CommandValues(['type' => 'cow', 'position' => $position]),
            ));
            self::assertFalse($outOfWorld->isSuccess());
            self::assertSame('Coordinates are outside the supported world bounds.', $outOfWorld->message());
        }
        self::assertSame(0, $calls);
    }

    public function testBuiltinRegistrarPublishesSummonSchemaAndEnforcesPermission(): void
    {
        $registry = $this->registry();
        $calledType = null;
        $calledSource = null;
        $callCount = 0;
        $permissions = new PermissionStore($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'permissions.json');
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function (): void {},
            static fn(): array => [],
            entityIdentifiers: static fn(): array => [
                'minecraft:zombie',
                'example:guardian',
                'minecraft:cow',
            ],
            summonEntity: static function (string $type, Position $position, ?Player $source) use (
                &$calledType,
                &$calledSource,
                &$callCount,
            ): bool {
                $calledType = $type;
                $calledSource = $source;
                ++$callCount;

                return true;
            },
        ))->register();

        self::assertSame(11, $registry->count());
        $commands = $registry->availableCommands(CommandSenderType::CONSOLE, static fn(string $permission): bool => true);
        $summon = $commands[count($commands) - 1];
        self::assertSame('summon', $summon->definition->name);
        self::assertSame('bedriox.command.summon', $summon->definition->permission);
        self::assertSame(
            ['cow', 'zombie', 'example:guardian'],
            $summon->arguments->overloads()[0]->parameters()[0]->softEnumValue()?->values(),
        );

        $denied = new SummonConsoleSender(false);
        self::assertFalse($registry->dispatch($denied, 'summon cow 0 64 0')->isSuccess());
        self::assertSame('You do not have permission to use this command.', $denied->messages[0]);
        self::assertSame(0, $callCount);

        $allowed = new SummonConsoleSender();
        self::assertTrue($registry->dispatch($allowed, 'summon cow 0 64 0')->isSuccess());
        self::assertSame(1, $callCount);
        self::assertSame('minecraft:cow', $calledType);
        self::assertNull($calledSource);
    }

    public function testBuiltinRegistrarPreservesDefaultsWithoutCompleteSummonCallbacks(): void
    {
        $registry = $this->registry();
        $permissions = new PermissionStore($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'permissions.json');
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function (): void {},
            static fn(): array => [],
            entityIdentifiers: static fn(): array => ['minecraft:cow'],
        ))->register();

        self::assertSame(10, $registry->count());
        self::assertNotContains('summon', array_map(
            static fn($definition): string => $definition->name,
            $registry->availableDefinitions(CommandSenderType::CONSOLE, static fn(string $permission): bool => true),
        ));
    }

    private function registry(): CommandRegistry
    {
        $plugins = new SummonPluginControl();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $ownership = new PluginOwnershipRegistry();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-summon-' . bin2hex(random_bytes(8));

        return new CommandRegistry($plugins, $execution, $actions, $ownership, $events);
    }

    /** @param list<string> $values */
    private static function entityTypes(array $values): CommandSoftEnum
    {
        return new class ($values) implements CommandSoftEnum {
            /** @param list<string> $values */
            public function __construct(private array $values) {}

            public function name(): string
            {
                return 'bedriox:entity_identifiers';
            }

            public function values(): array
            {
                return $this->values;
            }

            public function replace(array $values): bool
            {
                if ($values === $this->values) {
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

    private static function player(Position $position): Player
    {
        return new Player(
            'Summoner',
            '00000000-0000-0000-0000-000000000001',
            $position,
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}

class SummonConsoleSender implements CommandSender
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(private readonly bool $permitted = true) {}

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
        return $this->permitted;
    }
}

final class SummonPlayerSender extends SummonConsoleSender implements PlayerCommandSender
{
    public function __construct(private readonly Player $player)
    {
        parent::__construct();
    }

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
}

final class SummonPluginControl implements PluginRuntimeControl
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
