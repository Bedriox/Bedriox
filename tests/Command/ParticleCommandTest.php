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

use Bedriox\Api\Command\AllowedCommandSenders;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Particle\Particle;
use Bedriox\Api\World\Particle\ParticleType;
use Bedriox\Api\World\Particle\SimpleParticle;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldActions;
use Bedriox\Server\Command\Default\ParticleCommand;
use Bedriox\Server\Plugin\Command\CommandArgumentBinder;
use Bedriox\Server\Plugin\Command\CommandBindingException;
use PHPUnit\Framework\TestCase;

final class ParticleCommandTest extends TestCase
{
    public function testSchemaUsesTypedCurrentParticleNamesAndPlayerWorld(): void
    {
        $command = new ParticleCommand();
        self::assertSame('bedriox.command.particle', $command->definition()->permission);
        self::assertSame(AllowedCommandSenders::PLAYER_ONLY, $command->definition()->allowedSenders);
        $usage = $command->defineArguments()->usage('particle');
        self::assertCount(2, $usage);
        self::assertStringStartsWith('/particle <particle:', $usage[0]);
        self::assertStringContainsString('minecraft:basic_bubble_particle', $usage[0]);
        self::assertStringContainsString('minecraft:wither_boss_invulnerable', $usage[0]);
        self::assertStringEndsWith(' <position>', $usage[1]);

        $expectedNames = array_map(
            static fn(ParticleType $type): string => $type->value,
            ParticleType::cases(),
        );
        foreach ($command->defineArguments()->overloads() as $overload) {
            self::assertSame($expectedNames, $overload->parameters()[0]->choices());
        }
        self::assertNotContains('minecraft:heart', $expectedNames);
        self::assertNotContains('minecraft:flame', $expectedNames);
    }

    public function testOnlyExactRegisteredParticleNamesReachTheWorldAction(): void
    {
        $command = new ParticleCommand();
        $binder = new CommandArgumentBinder(static fn(): array => []);
        $spawned = [];
        $world = new World('world', 1, new WorldActions(
            static fn(World $world, BlockPosition $position): never => throw new \LogicException('Not used by this test.'),
            static function (World $world, BlockPosition $position, string $identifier): void {},
            static function (World $world, Position $position, Particle $particle, ?array $players) use (&$spawned): void {
                $spawned[] = [$world, $position, $particle];
            },
        ));
        $player = new Player(
            'ParticleTester',
            '00000000-0000-0000-0000-000000000001',
            new Position(1.0, 65.0, 2.0, world: $world),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
        $sender = new ParticleCommandTestSender($player);

        $heart = $binder->bind($command->defineArguments(), $sender, ['minecraft:heart_particle']);
        self::assertSame(ParticleType::HEART, $heart->enum('particle', ParticleType::class));
        $result = $command->execute(new CommandContext($sender, 'particle', $heart));
        self::assertTrue($result->isSuccess());
        self::assertCount(1, $spawned);
        self::assertSame($world, $spawned[0][0]);
        self::assertInstanceOf(SimpleParticle::class, $spawned[0][2]);
        self::assertSame(ParticleType::HEART, $spawned[0][2]->type());

        foreach (['minecraft:heart', 'heart', 'minecraft:flame', 'flame'] as $unknown) {
            try {
                $binder->bind($command->defineArguments(), $sender, [$unknown]);
                self::fail("Particle alias '{$unknown}' unexpectedly bound.");
            } catch (CommandBindingException $failure) {
                $message = $failure->getMessage();
                self::assertStringContainsString('particle', $message);
                self::assertStringContainsString('not found', $message);
                self::assertLessThanOrEqual(1_024, strlen($message));
                self::assertStringNotContainsString('minecraft:heart_particle,', $message);
            }
        }
        self::assertCount(1, $spawned);
    }
}

final class ParticleCommandTestSender implements PlayerCommandSender
{
    public function __construct(private readonly Player $player) {}

    public function type(): CommandSenderType
    {
        return CommandSenderType::PLAYER;
    }

    public function name(): string
    {
        return $this->player->name;
    }

    public function sendMessage(string $message): void {}

    public function hasPermission(string $permission): bool
    {
        return true;
    }

    public function player(): Player
    {
        return $this->player;
    }
}
