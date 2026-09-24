<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Command;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\Default\DeopCommand;
use Bedriox\Server\Command\Default\OpCommand;
use Bedriox\Server\Command\Default\PermissionCommand;
use Bedriox\Server\Permission\PermissionStore;
use PHPUnit\Framework\TestCase;

final class DefaultCommandAuthorityTest extends TestCase
{
    private const string UUID = '00000000-0000-0000-0000-000000000001';

    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory === null) {
            return;
        }
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testOperatorChangesRequestAbilitiesBeforeCommandRefreshAndIgnoreNoOpMutations(): void
    {
        $store = $this->store();
        $player = $this->player();
        $changes = [];
        $refresh = static function (Player $changed, bool $includeAbilities) use (&$changes): void {
            $changes[] = [$changed->uuid, $includeAbilities];
        };
        $sender = new AuthorityCommandSender();

        (new OpCommand($store, $refresh))->execute(new CommandContext($sender, 'op', new CommandValues(['player' => $player])));
        self::assertSame([[self::UUID, true]], $changes);
        self::assertTrue($store->isOperator(self::UUID));

        (new OpCommand($store, $refresh))->execute(new CommandContext($sender, 'op', new CommandValues(['player' => $player])));
        self::assertSame([[self::UUID, true]], $changes, 'An unchanged operator assignment must not refresh the client.');

        (new DeopCommand($store, $refresh))->execute(new CommandContext($sender, 'deop', new CommandValues(['player' => $player])));
        self::assertSame([[self::UUID, true], [self::UUID, true]], $changes);
        self::assertFalse($store->isOperator(self::UUID));

        (new DeopCommand($store, $refresh))->execute(new CommandContext($sender, 'deop', new CommandValues(['player' => $player])));
        self::assertCount(2, $changes, 'An unchanged deoperator assignment must not refresh the client.');
    }

    public function testPermissionChangesRequestOnlyAvailableCommandsRefreshAndIgnoreNoOpMutations(): void
    {
        $store = $this->store();
        $player = $this->player();
        $changes = [];
        $refresh = static function (Player $changed, bool $includeAbilities) use (&$changes): void {
            $changes[] = [$changed->uuid, $includeAbilities];
        };
        $command = new PermissionCommand($store, $refresh);
        $sender = new AuthorityCommandSender();

        $command->execute(new CommandContext($sender, 'permission', new CommandValues([
            'grant' => 'grant',
            'player' => $player,
            'node' => 'example.use',
        ])));
        self::assertSame([[self::UUID, false]], $changes);

        $command->execute(new CommandContext($sender, 'permission', new CommandValues([
            'grant' => 'grant',
            'player' => $player,
            'node' => 'example.use',
        ])));
        self::assertSame([[self::UUID, false]], $changes, 'An unchanged grant must not refresh the client.');

        $command->execute(new CommandContext($sender, 'permission', new CommandValues([
            'revoke' => 'revoke',
            'player' => $player,
            'node' => 'example.use',
        ])));
        self::assertSame([[self::UUID, false], [self::UUID, false]], $changes);

        $command->execute(new CommandContext($sender, 'permission', new CommandValues([
            'revoke' => 'revoke',
            'player' => $player,
            'node' => 'example.use',
        ])));
        self::assertCount(2, $changes, 'An unchanged revoke must not refresh the client.');
    }

    private function store(): PermissionStore
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-authority-' . bin2hex(random_bytes(8));

        return new PermissionStore($this->directory . DIRECTORY_SEPARATOR . 'permissions.json');
    }

    private function player(): Player
    {
        return new Player(
            'Player',
            self::UUID,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}

final class AuthorityCommandSender implements CommandSender
{
    public function type(): CommandSenderType
    {
        return CommandSenderType::CONSOLE;
    }

    public function name(): string
    {
        return 'Console';
    }

    public function sendMessage(string $message): void {}

    public function hasPermission(string $permission): bool
    {
        return true;
    }
}
