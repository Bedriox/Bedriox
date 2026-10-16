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
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot;
use Bedriox\Api\World\Position;
use Bedriox\Server\Command\Default\OnlinePlayerResolver;
use Bedriox\Server\Command\Default\ScoreboardCommand;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\Scoreboard\ScoreboardRegistry;
use PHPUnit\Framework\TestCase;

final class ScoreboardCommandTest extends TestCase
{
    public function testObjectiveAndPlayerOperationsUseTheSharedManager(): void
    {
        $player = self::player();
        $registry = new ScoreboardRegistry(new PluginOwnershipRegistry(), static fn(): array => [$player]);
        $manager = $registry->forOwner('Bedriox');
        $command = new ScoreboardCommand($manager, new OnlinePlayerResolver(static fn(): array => [$player]));
        $sender = new ScoreboardCommandSender();

        $created = $command->execute(self::context($sender, [
            'objectives' => 'objectives',
            'add' => 'add',
            'objective' => 'kills',
            'displayName' => 'Player Kills',
        ]));
        self::assertTrue($created->isSuccess());
        $objective = $manager->getObjective('kills');
        self::assertNotNull($objective);

        $set = $command->execute(self::context($sender, [
            'players' => 'players',
            'operation' => 'set',
            'player' => $player,
            'objective' => 'kills',
            'score' => 4,
        ]));
        self::assertTrue($set->isSuccess());
        self::assertSame(4, $objective->getScore($player));

        $added = $command->execute(self::context($sender, [
            'players' => 'players',
            'operation' => 'add',
            'player' => $player,
            'objective' => 'kills',
            'score' => 3,
        ]));
        self::assertTrue($added->isSuccess());
        self::assertSame(7, $objective->getScore($player));

        $displayed = $command->execute(self::context($sender, [
            'objectives' => 'objectives',
            'setdisplay' => 'setdisplay',
            'slot' => 'sidebar',
            'objective' => 'kills',
        ]));
        self::assertTrue($displayed->isSuccess());
        self::assertTrue($objective->isShowingTo(
            $player,
            ScoreboardDisplaySlot::SIDEBAR,
        ));

        $reset = $command->execute(self::context($sender, [
            'players' => 'players',
            'reset' => 'reset',
            'player' => $player,
            'objective' => 'kills',
        ]));
        self::assertTrue($reset->isSuccess());
        self::assertNull($objective->getScore($player));
    }

    public function testMissingObjectivesFailWithoutCreatingState(): void
    {
        $player = self::player();
        $registry = new ScoreboardRegistry(new PluginOwnershipRegistry(), static fn(): array => [$player]);
        $command = new ScoreboardCommand(
            $registry->forOwner('Bedriox'),
            new OnlinePlayerResolver(static fn(): array => [$player]),
        );
        $result = $command->execute(self::context(new ScoreboardCommandSender(), [
            'players' => 'players',
            'get' => 'get',
            'player' => $player,
            'objective' => 'missing',
        ]));

        self::assertFalse($result->isSuccess());
        self::assertSame("Scoreboard objective 'missing' does not exist.", $result->message());
    }

    public function testDuplicateObjectiveIsReportedAsACommandFailure(): void
    {
        $registry = new ScoreboardRegistry(new PluginOwnershipRegistry(), static fn(): array => []);
        $manager = $registry->forOwner('Bedriox');
        $manager->createObjective('kills', 'Kills');
        $command = new ScoreboardCommand(
            $manager,
            new OnlinePlayerResolver(static fn(): array => []),
        );

        $result = $command->execute(self::context(new ScoreboardCommandSender(), [
            'objectives' => 'objectives',
            'add' => 'add',
            'objective' => 'kills',
            'displayName' => 'Player Kills',
        ]));

        self::assertFalse($result->isSuccess());
        self::assertSame('Scoreboard objective already exists: kills', $result->message());
    }

    public function testSchemaIsOperatorOnlyAndCoversTheSupportedSurface(): void
    {
        $registry = new ScoreboardRegistry(new PluginOwnershipRegistry(), static fn(): array => []);
        $command = new ScoreboardCommand(
            $registry->forOwner('Bedriox'),
            new OnlinePlayerResolver(static fn(): array => []),
        );

        self::assertSame('bedriox.command.scoreboard', $command->definition()->permission);
        self::assertCount(9, $command->defineArguments()->usage('scoreboard'));
    }

    /** @param array<string, mixed> $values */
    private static function context(CommandSender $sender, array $values): CommandContext
    {
        return new CommandContext($sender, 'scoreboard', new CommandValues($values));
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

final class ScoreboardCommandSender implements CommandSender
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
