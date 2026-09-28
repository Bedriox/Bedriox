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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Plugin\Command\CommandArgumentBinder;
use Bedriox\Server\Plugin\Command\CommandBindingException;
use Bedriox\Server\Simulation\Position as InternalPosition;
use PHPUnit\Framework\TestCase;

final class CommandArgumentBinderTest extends TestCase
{
    public function testBindsTypedValuesAndAppliesOptionalDefaults(): void
    {
        $binder = new CommandArgumentBinder(static fn(): array => []);
        $arguments = CommandArguments::create()
            ->addArgument(CommandParameter::integer('amount')->minimum(1)->maximum(64))
            ->addArgument(CommandParameter::boolean('enabled'))
            ->addArgument(CommandParameter::choice('mode', ['safe', 'fast']))
            ->addArgument(CommandParameter::string('note')->optional(default: 'default'));

        $values = $binder->bind($arguments, new BinderCommandSender(), ['4', 'true', 'FAST']);

        self::assertSame(4, $values->integer('amount'));
        self::assertTrue($values->boolean('enabled'));
        self::assertSame('fast', $values->choice('mode'));
        self::assertSame('default', $values->string('note'));
    }

    public function testSelectsTheMatchingLiteralOverload(): void
    {
        $arguments = CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('give'))
                ->addArgument(CommandParameter::integer('amount')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('clear')));

        $values = (new CommandArgumentBinder(static fn(): array => []))
            ->bind($arguments, new BinderCommandSender(), ['give', '3']);

        self::assertSame('give', $values->string('give'));
        self::assertSame(3, $values->integer('amount'));
        self::assertFalse($values->has('clear'));
    }

    public function testOnlinePlayerResolvesOnlyConnectedExactNames(): void
    {
        $connected = self::player('Alex', true);
        $disconnected = self::player('Steve', false);
        $binder = new CommandArgumentBinder(static fn(): array => [$disconnected, $connected]);
        $arguments = CommandArguments::create()->addArgument(CommandParameter::onlinePlayer('player'));

        self::assertSame($connected, $binder->bind($arguments, new BinderCommandSender(), ['aLeX'])->player('player'));

        $this->expectException(CommandBindingException::class);
        $this->expectExceptionMessage("Player 'Steve' is not connected.");
        $binder->bind($arguments, new BinderCommandSender(), ['Steve']);
    }

    public function testPlayerSelectorsExcludeDisconnectedPlayersAndResolveSelf(): void
    {
        $alex = self::player('Alex', true);
        $steve = self::player('Steve', false);
        $binder = new CommandArgumentBinder(static fn(): array => [$steve, $alex]);
        $arguments = CommandArguments::create()->addArgument(CommandParameter::players('targets'));

        self::assertSame([$alex], $binder->bind($arguments, new BinderPlayerCommandSender($alex), ['@a'])->players('targets'));
        self::assertSame([$alex], $binder->bind($arguments, new BinderPlayerCommandSender($alex), ['@s'])->players('targets'));
    }

    public function testEntitySelectorsResolveAllRootsAndBoundedFilters(): void
    {
        $alex = self::player('Alex', true, new Position(0.0, 64.0, 0.0));
        $steve = self::player('Steve', true, new Position(8.0, 64.0, 0.0));
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000003',
            200,
            'world',
            new InternalPosition(2.0, 64.0, 0.0),
        );
        $binder = new CommandArgumentBinder(
            static fn(): array => [$steve, $alex],
            static fn(): array => [$zombie],
            static fn(int $upperBound): int => 0,
            static fn(): Position => new Position(0.5, 64.0, 0.5),
        );
        $many = CommandArguments::create()->addArgument(CommandParameter::entities('targets'));
        $single = CommandArguments::create()->addArgument(CommandParameter::entity('target'));
        $sender = new BinderPlayerCommandSender($alex);

        self::assertSame([$alex, $steve], $binder->bind($many, $sender, ['@a'])->entities('targets'));
        self::assertSame([$alex], $binder->bind($many, $sender, ['@s'])->entities('targets'));
        self::assertSame($alex, $binder->bind($single, $sender, ['@p'])->entity('target'));
        self::assertSame($zombie, $binder->bind(
            $single,
            $sender,
            ['@e[type=zombie,distance=..5,limit=1,sort=nearest]'],
        )->entity('target'));
        self::assertSame($steve, $binder->bind($single, $sender, ['@r'])->entity('target'));
        self::assertSame($zombie, $binder->bind($single, $sender, [$zombie->getUniqueId()])->entity('target'));
        self::assertSame($zombie, $binder->bind(
            $single,
            new BinderCommandSender(),
            ['@n[type=zombie,distance=..4]'],
        )->entity('target'));
    }

    public function testEntitySelectorRejectsMultipleResultsAndUnboundedLimits(): void
    {
        $alex = self::player('Alex', true);
        $steve = self::player('Steve', true);
        $binder = new CommandArgumentBinder(static fn(): array => [$alex, $steve]);
        $single = CommandArguments::create()->addArgument(CommandParameter::entity('target'));

        try {
            $binder->bind($single, new BinderPlayerCommandSender($alex), ['@a']);
            self::fail('A single entity parameter accepted multiple targets.');
        } catch (CommandBindingException $failure) {
            self::assertSame('The target selector matched more than one entity.', $failure->getMessage());
        }

        $this->expectException(CommandBindingException::class);
        $this->expectExceptionMessage('Selector limit exceeds 128 results.');
        $binder->bind($single, new BinderPlayerCommandSender($alex), ['@e[limit=129]']);
    }

    public function testInvalidTypedValueReportsTheParameterFailure(): void
    {
        $arguments = CommandArguments::create()
            ->addArgument(CommandParameter::integer('amount')->minimum(1)->maximum(4));

        $this->expectException(CommandBindingException::class);
        $this->expectExceptionMessage("Argument 'amount' is outside its allowed range.");
        (new CommandArgumentBinder(static fn(): array => []))
            ->bind($arguments, new BinderCommandSender(), ['5']);
    }

    public function testJsonConsumesTheRemainingStructuredValue(): void
    {
        $arguments = CommandArguments::create()->addArgument(CommandParameter::json('data'));
        $values = (new CommandArgumentBinder(static fn(): array => []))->bind(
            $arguments,
            new BinderCommandSender(),
            ['{"enabled":', 'true,', '"label":', '"hello world"}'],
        );

        self::assertSame(['enabled' => true, 'label' => 'hello world'], $values->json('data'));
    }

    private static function player(string $name, bool $connected, ?Position $position = null): Player
    {
        return new Player(
            $name,
            $name === 'Alex' ? '00000000-0000-0000-0000-000000000001' : '00000000-0000-0000-0000-000000000002',
            $position ?? new Position(0.0, 64.0, 0.0),
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

class BinderCommandSender implements CommandSender
{
    public function type(): CommandSenderType
    {
        return CommandSenderType::CONSOLE;
    }

    public function name(): string
    {
        return 'test';
    }

    public function sendMessage(string $message): void {}

    public function hasPermission(string $permission): bool
    {
        return true;
    }
}

final class BinderPlayerCommandSender extends BinderCommandSender implements PlayerCommandSender
{
    public function __construct(private readonly Player $target) {}

    public function type(): CommandSenderType
    {
        return CommandSenderType::PLAYER;
    }

    public function player(): Player
    {
        return $this->target;
    }
}
