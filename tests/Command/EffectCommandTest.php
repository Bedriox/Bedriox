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
use Bedriox\Api\Effect\EffectActions;
use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\Default\EffectCommand;
use PHPUnit\Framework\TestCase;

final class EffectCommandTest extends TestCase
{
    public function testSchemaUsesTypedCurrentEffectsAndBoundedValues(): void
    {
        $command = new EffectCommand();
        self::assertSame('bedriox.command.effect', $command->definition()->permission);
        $usage = $command->defineArguments()->usage('effect');
        self::assertCount(4, $usage);
        self::assertSame('/effect <player> clear', $usage[0]);
        self::assertStringStartsWith('/effect <player> clear <clearEffect:', $usage[1]);
        self::assertStringContainsString('wind_charged', $usage[2]);
        self::assertStringNotContainsString('minecraft:', $usage[2]);
        self::assertStringEndsWith('[seconds] [amplifier] [hideParticles]', $usage[2]);
        self::assertStringEndsWith(' infinite [amplifier] [hideParticles]', $usage[3]);
    }

    public function testExecutionUsesThePlayersAuthoritativeEffectActions(): void
    {
        $calls = [];
        $actions = new EffectActions(
            static function (EffectInstance $effect, EffectCause $cause) use (&$calls): void {
                $calls[] = ['add', $effect, $cause];
            },
            static function (EffectType $effect, EffectCause $cause) use (&$calls): void {
                $calls[] = ['remove', $effect, $cause];
            },
            static function (EffectCause $cause) use (&$calls): void {
                $calls[] = ['clear', $cause];
            },
        );
        $player = new Player(
            'Target',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
            effectActions: $actions,
        );
        $command = new EffectCommand();
        $sender = new EffectCommandSender();

        $applied = $command->execute(new CommandContext($sender, 'effect', new CommandValues([
            'player' => $player,
            'effect' => 'speed',
            'seconds' => 10,
            'amplifier' => 1,
            'hideParticles' => true,
        ])));
        self::assertTrue($applied->isSuccess());
        self::assertSame('add', $calls[0][0]);
        self::assertInstanceOf(EffectInstance::class, $calls[0][1]);
        self::assertSame(EffectType::SPEED, $calls[0][1]->type);
        self::assertSame(200, $calls[0][1]->durationTicks);
        self::assertFalse($calls[0][1]->visible);
        self::assertSame(EffectCause::COMMAND, $calls[0][2]);

        $command->execute(new CommandContext($sender, 'effect', new CommandValues([
            'player' => $player,
            'clear' => 'clear',
            'clearEffect' => 'speed',
        ])));
        $command->execute(new CommandContext($sender, 'effect', new CommandValues([
            'player' => $player,
            'clear' => 'clear',
        ])));
        self::assertSame(['remove', EffectType::SPEED, EffectCause::COMMAND], $calls[1]);
        self::assertSame(['clear', EffectCause::COMMAND], $calls[2]);
    }
}

final class EffectCommandSender implements CommandSender
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
