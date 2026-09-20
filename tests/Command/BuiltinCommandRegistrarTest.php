<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Command;

use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\BuiltinCommandRegistrar;
use Bedriox\Server\Command\Default\OnlinePlayerResolver;
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

final class BuiltinCommandRegistrarTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $files = glob($directory . DIRECTORY_SEPARATOR . '*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        $this->temporaryDirectories = [];
    }

    public function testRegistrarPreservesDefaultCommandDefinitionsAndAliases(): void
    {
        [$registry, $permissions] = $this->registry();
        (new BuiltinCommandRegistrar($registry, $permissions, static fn(): array => [], static function (): void {}))->register();

        self::assertSame(7, $registry->count());
        $definitions = $registry->availableDefinitions(CommandSenderType::CONSOLE, static fn(string $permission): bool => true);
        self::assertSame(
            ['version', 'help', 'list', 'stop', 'op', 'deop', 'permission'],
            array_map(static fn($definition): string => $definition->name, $definitions),
        );
        self::assertSame(['ver'], $definitions[0]->aliases);
        self::assertSame(['commands'], $definitions[1]->aliases);
        self::assertNull($definitions[2]->permission);
        self::assertSame('bedriox.command.stop', $definitions[3]->permission);
        self::assertSame('bedriox.command.op', $definitions[4]->permission);
        self::assertSame('bedriox.command.op', $definitions[5]->permission);
        self::assertSame(['perm'], $definitions[6]->aliases);
        self::assertSame('bedriox.command.permission', $definitions[6]->permission);

        $sender = new BuiltinCommandSender();
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'ver'));
        self::assertSame(
            'This server is running Bedriox version 0.1.0-alpha.1 (protocol 2193).',
            $sender->messages[0],
        );
        self::assertSame('Visit https://bedriox.com', $sender->messages[1]);
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'commands'));
        self::assertContains('Available commands (7):', $sender->messages);
    }

    public function testPlayerListOperatorAndPermissionCommandsPreserveBehavior(): void
    {
        [$registry, $permissions] = $this->registry();
        $amy = $this->player('Amy', '00000000-0000-0000-0000-000000000001');
        $zed = $this->player('zed', '00000000-0000-0000-0000-000000000002');
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [$zed, $amy],
            static function (): void {},
        ))->register();
        $sender = new BuiltinCommandSender();

        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'list'));
        self::assertSame('There are 2 players online.', $sender->messages[0]);
        self::assertSame('Players: Amy, zed', $sender->messages[1]);
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'op aMY'));
        self::assertSame('Amy is now an operator.', array_pop($sender->messages));
        self::assertTrue($permissions->isOperator($amy->uuid));
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'op Amy'));
        self::assertSame('Amy is already an operator.', array_pop($sender->messages));
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'deop AMY'));
        self::assertSame('Amy is no longer an operator.', array_pop($sender->messages));
        self::assertFalse($permissions->isOperator($amy->uuid));

        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'perm grant amy example.use'));
        self::assertSame('Permission assignment updated.', array_pop($sender->messages));
        self::assertTrue($permissions->hasPermission($amy->uuid, 'example.use'));
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'permission list Amy'));
        self::assertSame('Permissions for Amy: example.use', array_pop($sender->messages));
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'permission revoke Amy example.use'));
        self::assertSame('Permission assignment updated.', array_pop($sender->messages));
        self::assertFalse($permissions->hasPermission($amy->uuid, 'example.use'));

        self::assertSame(CommandResult::FAILURE, $registry->dispatch($sender, 'op Missing'));
        self::assertSame('Player is not online.', array_pop($sender->messages));
        self::assertSame(CommandResult::USAGE, $registry->dispatch($sender, 'permission invalid Amy'));
        self::assertSame('Usage: permission <list|grant|revoke> <player> [node]', array_pop($sender->messages));
    }

    public function testStopCommandInvokesTheExistingShutdownBoundaryOnlyAfterValidUsage(): void
    {
        [$registry, $permissions] = $this->registry();
        $stops = 0;
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function () use (&$stops): void {
                ++$stops;
            },
        ))->register();
        $sender = new BuiltinCommandSender();

        self::assertSame(CommandResult::USAGE, $registry->dispatch($sender, 'stop now'));
        self::assertSame(0, $stops);
        self::assertSame('Usage: stop', array_pop($sender->messages));
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'stop'));
        self::assertSame(1, $stops);
        self::assertSame('Stopping the server...', array_pop($sender->messages));
    }

    public function testOnlinePlayerResolverUsesFreshSnapshotsAndCaseInsensitiveExactNames(): void
    {
        $players = [$this->player('First', '00000000-0000-0000-0000-000000000001')];
        $resolver = new OnlinePlayerResolver(static function () use (&$players): array {
            return $players;
        });

        self::assertSame('First', $resolver->find('fIrSt')?->name);
        self::assertNull($resolver->find('Fir'));
        $players = [$this->player('Second', '00000000-0000-0000-0000-000000000002')];
        self::assertNull($resolver->find('First'));
        self::assertSame('Second', $resolver->find('second')?->name);
    }

    public function testPlayerFacingListAndVersionMessagesUseSeparateColors(): void
    {
        [$registry, $permissions] = $this->registry();
        $player = $this->player('Amy', '00000000-0000-0000-0000-000000000001');
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [$player],
            static function (): void {},
        ))->register();
        $sender = new BuiltinCommandSender(CommandSenderType::PLAYER);

        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'list'));
        self::assertSame("\u{00a7}aThere is 1 player online.\u{00a7}r", $sender->messages[0]);
        self::assertSame("\u{00a7}bPlayers: Amy\u{00a7}r", $sender->messages[1]);

        $versionSender = new BuiltinCommandSender(CommandSenderType::PLAYER);
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($versionSender, 'version'));
        self::assertSame(
            "\u{00a7}aThis server is running Bedriox version 0.1.0-alpha.1 (protocol 2193).\u{00a7}r",
            $versionSender->messages[0],
        );
        self::assertSame("\u{00a7}bVisit https://bedriox.com\u{00a7}r", $versionSender->messages[1]);
    }

    /** @return array{CommandRegistry, PermissionStore} */
    private function registry(): array
    {
        $plugins = new BuiltinPluginControl();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $ownership = new PluginOwnershipRegistry();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-builtins-' . bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $directory;

        return [
            new CommandRegistry($plugins, $execution, $actions, $ownership, $events),
            new PermissionStore($directory . DIRECTORY_SEPARATOR . 'permissions.json'),
        ];
    }

    private function player(string $name, string $uuid): Player
    {
        return new Player(
            $name,
            $uuid,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}

final class BuiltinCommandSender implements CommandSender
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(private readonly CommandSenderType $senderType = CommandSenderType::CONSOLE) {}

    public function type(): CommandSenderType
    {
        return $this->senderType;
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

final class BuiltinPluginControl implements PluginRuntimeControl
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
