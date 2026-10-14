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
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
use Bedriox\Server\Command\Default\LocateBiomeCatalog;
use Bedriox\Server\Command\Default\LocateCommand;
use Bedriox\Server\Command\Default\LocateStructure;
use Bedriox\Server\Plugin\Command\CommandArgumentBinder;
use Bedriox\Server\World\Generation\Structure\Location\LocatedFeature;
use PHPUnit\Framework\TestCase;

final class LocateCommandTest extends TestCase
{
    public function testSchemaMatchesBedrockOverloadsAndUsesBoundedEnums(): void
    {
        $command = new LocateCommand(
            static fn(Player $player, World $world, Position $position, LocateStructure $structure, bool $newOnly, \Closure $completion): bool => false,
            static fn(Player $player, World $world, Position $position, string $biome, \Closure $completion): bool => false,
        );
        self::assertSame('bedriox.command.locate', $command->definition()->permission);
        self::assertSame(AllowedCommandSenders::PLAYER_ONLY, $command->definition()->allowedSenders);
        $overloads = $command->defineArguments()->overloads();
        self::assertCount(2, $overloads);
        self::assertSame(['structure', 'structureType', 'useNewChunksOnly'], array_map(
            static fn($parameter): string => $parameter->name(),
            $overloads[0]->parameters(),
        ));
        self::assertSame(['biome', 'biomeType'], array_map(
            static fn($parameter): string => $parameter->name(),
            $overloads[1]->parameters(),
        ));
        self::assertSame(
            array_map(static fn(LocateStructure $structure): string => $structure->value, LocateStructure::cases()),
            $overloads[0]->parameters()[1]->choices(),
        );
        self::assertCount(88, LocateBiomeCatalog::identifiers());
        self::assertContains('minecraft:cherry_grove', LocateBiomeCatalog::identifiers());
    }

    public function testStructureAndBiomeResultsUseBedrockMessageShape(): void
    {
        $world = new World('world', 1);
        $player = new Player(
            'Locator',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0, world: $world),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
        $sender = new LocateCommandTestSender($player);
        $structureCompletion = null;
        $biomeCompletion = null;
        $command = new LocateCommand(
            static function (
                Player $actualPlayer,
                World $actual,
                Position $origin,
                LocateStructure $structure,
                bool $newOnly,
                \Closure $completion,
            ) use (&$structureCompletion): bool {
                $structureCompletion = $completion;
                return true;
            },
            static function (
                Player $actualPlayer,
                World $actual,
                Position $origin,
                string $biome,
                \Closure $completion,
            ) use (&$biomeCompletion): bool {
                $biomeCompletion = $completion;
                return true;
            },
        );
        $binder = new CommandArgumentBinder(static fn(): array => [$player]);

        $structure = $binder->bind($command->defineArguments(), $sender, ['structure', 'minecraft:village']);
        $result = $command->execute(new CommandContext($sender, 'locate', $structure));
        self::assertSame('Searching for minecraft:village...', $result->message());
        self::assertInstanceOf(\Closure::class, $structureCompletion);
        $structureCompletion(new LocatedFeature('minecraft:village', 300, 72, 400), null);
        self::assertSame(
            "\u{00a7}aThe nearest minecraft:village is at block 300, 72, 400 (500 blocks away)\u{00a7}r",
            $sender->messages[0],
        );

        $biome = $binder->bind($command->defineArguments(), $sender, ['biome', 'minecraft:cherry_grove']);
        $result = $command->execute(new CommandContext($sender, 'locate', $biome));
        self::assertSame('Searching for minecraft:cherry_grove...', $result->message());
        self::assertInstanceOf(\Closure::class, $biomeCompletion);
        $biomeCompletion(new LocatedFeature('minecraft:cherry_grove', -300, 95, 400), null);
        self::assertSame(
            "\u{00a7}aThe nearest minecraft:cherry_grove is at block -300, 95, 400 (500 blocks away)\u{00a7}r",
            $sender->messages[1],
        );
    }
}

final class LocateCommandTestSender implements PlayerCommandSender
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(private readonly Player $player) {}
    public function type(): CommandSenderType
    {
        return CommandSenderType::PLAYER;
    }
    public function name(): string
    {
        return $this->player->name;
    }
    public function sendMessage(string $message): void
    {
        $this->messages[] = $message;
    }
    public function hasPermission(string $permission): bool
    {
        return true;
    }
    public function player(): Player
    {
        return $this->player;
    }
}
