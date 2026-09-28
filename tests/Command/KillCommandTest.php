<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Tests\Command;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\Default\KillCommand;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position as InternalPosition;
use PHPUnit\Framework\TestCase;

final class KillCommandTest extends TestCase
{
    public function testPlayerCanKillSelfWithoutAnExplicitTarget(): void
    {
        $player = self::player();
        $accepted = [];
        $command = new KillCommand(static function (Player|Entity $target) use (&$accepted): bool {
            $accepted[] = $target;

            return true;
        });

        $result = $command->execute(new CommandContext(
            new KillPlayerSender($player, ['bedriox.command.kill.self']),
            'kill',
            new CommandValues(),
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame([$player], $accepted);
        self::assertSame('Kill requested for Player.', $result->message());
        self::assertSame('bedriox.command.kill', $command->definition()->permission);
        self::assertSame(['/kill', '/kill <targets>'], $command->defineArguments()->usage('kill'));
    }

    public function testExplicitTargetsRequireOtherPermissionAndRemainBounded(): void
    {
        $player = self::player();
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000002',
            200,
            'world',
            new InternalPosition(1.0, 64.0, 0.0),
        );
        $calls = 0;
        $command = new KillCommand(static function () use (&$calls): bool {
            ++$calls;

            return true;
        });

        $denied = $command->execute(new CommandContext(
            new KillPlayerSender($player, ['bedriox.command.kill.self']),
            'kill',
            new CommandValues(['targets' => [$player, $zombie]]),
        ));
        self::assertFalse($denied->isSuccess());
        self::assertSame(0, $calls);

        $accepted = $command->execute(new CommandContext(
            new KillConsoleSender(),
            'kill',
            new CommandValues(['targets' => [$zombie]]),
        ));
        self::assertTrue($accepted->isSuccess());
        self::assertSame(1, $calls);
        self::assertSame('Kill requested for zombie #200.', $accepted->message());
    }

    public function testConsoleRequiresAnExplicitTarget(): void
    {
        $command = new KillCommand(static fn(): bool => true);
        $result = $command->execute(new CommandContext(
            new KillConsoleSender(),
            'kill',
            new CommandValues(),
        ));

        self::assertFalse($result->isSuccess());
        self::assertSame('A target is required when running this command from the console.', $result->message());
    }

    private static function player(): Player
    {
        return new Player(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}

class KillConsoleSender implements CommandSender
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

final class KillPlayerSender extends KillConsoleSender implements PlayerCommandSender
{
    /** @param list<string> $permissions */
    public function __construct(private readonly Player $player, private readonly array $permissions) {}

    public function type(): CommandSenderType
    {
        return CommandSenderType::PLAYER;
    }

    public function player(): Player
    {
        return $this->player;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
