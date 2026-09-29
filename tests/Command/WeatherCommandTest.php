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
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use Bedriox\Api\World\World;
use Bedriox\Server\Command\Default\WeatherCommand;
use PHPUnit\Framework\TestCase;

final class WeatherCommandTest extends TestCase
{
    public function testSchemaUsesTypedWeatherAndBoundedOptionalDuration(): void
    {
        $command = self::command();

        self::assertSame('bedriox.command.weather', $command->definition()->permission);
        self::assertSame([
            '/weather <type:clear|rain|thunder> [durationSeconds]',
            '/weather query',
        ], $command->defineArguments()->usage('weather'));
    }

    public function testConsoleUsesDefaultWorldAndMayOmitDuration(): void
    {
        $requestedWorlds = [];
        $requestedTypes = [];
        $requestedDurations = [];
        $command = self::command($requestedWorlds, $requestedTypes, $requestedDurations);

        $result = $command->execute(new CommandContext(
            new WeatherConsoleSender(),
            'weather',
            new CommandValues(['type' => WeatherType::RAIN]),
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame('Set the weather to rain.', $result->message());
        self::assertSame([null], $requestedWorlds);
        self::assertSame([WeatherType::RAIN], $requestedTypes);
        self::assertSame([null], $requestedDurations);
    }

    public function testPlayerWorldAndExplicitDurationReachAuthoritativeCallback(): void
    {
        $world = new World('nether', 1);
        $player = new Player(
            'WeatherTester',
            '10000000-0000-4000-8000-000000000001',
            new Position(1.0, 70.0, 2.0, 0.0, 0.0, $world),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
        $requestedWorlds = [];
        $requestedTypes = [];
        $requestedDurations = [];
        $command = self::command($requestedWorlds, $requestedTypes, $requestedDurations);

        $result = $command->execute(new CommandContext(
            new WeatherPlayerSender($player),
            'weather',
            new CommandValues([
                'type' => WeatherType::THUNDER,
                'durationSeconds' => 120,
            ]),
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame('Set the weather to thunder for 120 seconds.', $result->message());
        self::assertSame([$world], $requestedWorlds);
        self::assertSame([WeatherType::THUNDER], $requestedTypes);
        self::assertSame([120], $requestedDurations);
    }

    public function testQueryUsesTheSendersWorldAndReportsRemainingSeconds(): void
    {
        $world = new World('overworld', 1);
        $queriedWorlds = [];
        $command = new WeatherCommand(
            static function (?World $queriedWorld) use (&$queriedWorlds): WeatherState {
                $queriedWorlds[] = $queriedWorld;

                return new WeatherState(WeatherType::CLEAR, 1_219);
            },
            static fn(?World $world, WeatherType $type, ?int $durationSeconds): WeatherState =>
                WeatherState::fromCommandDuration($type, $durationSeconds ?? 600),
        );
        $player = new Player(
            'WeatherTester',
            '10000000-0000-4000-8000-000000000002',
            new Position(1.0, 70.0, 2.0, 0.0, 0.0, $world),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );

        $result = $command->execute(new CommandContext(
            new WeatherPlayerSender($player),
            'weather',
            new CommandValues(['query' => 'query']),
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame('The weather is clear with 60 seconds remaining.', $result->message());
        self::assertSame([$world], $queriedWorlds);
    }

    public function testUnavailableWorldFailsWithoutInventingState(): void
    {
        $command = new WeatherCommand(
            static fn(?World $world): ?WeatherState => null,
            static fn(?World $world, WeatherType $type, ?int $durationSeconds): ?WeatherState => null,
        );
        $sender = new WeatherConsoleSender();

        $query = $command->execute(new CommandContext(
            $sender,
            'weather',
            new CommandValues(['query' => 'query']),
        ));
        $set = $command->execute(new CommandContext(
            $sender,
            'weather',
            new CommandValues(['type' => WeatherType::CLEAR]),
        ));

        self::assertFalse($query->isSuccess());
        self::assertFalse($set->isSuccess());
        self::assertSame('World weather is unavailable.', $query->message());
        self::assertSame('World weather is unavailable.', $set->message());
    }

    /**
     * @param list<World|null>   $requestedWorlds
     * @param list<WeatherType>  $requestedTypes
     * @param list<int|null>     $requestedDurations
     */
    private static function command(
        array &$requestedWorlds = [],
        array &$requestedTypes = [],
        array &$requestedDurations = [],
    ): WeatherCommand {
        return new WeatherCommand(
            static fn(?World $world): WeatherState => new WeatherState(WeatherType::CLEAR, 600 * 20),
            static function (?World $world, WeatherType $type, ?int $durationSeconds) use (
                &$requestedWorlds,
                &$requestedTypes,
                &$requestedDurations,
            ): WeatherState {
                $requestedWorlds[] = $world;
                $requestedTypes[] = $type;
                $requestedDurations[] = $durationSeconds;

                return WeatherState::fromCommandDuration($type, $durationSeconds ?? 600);
            },
        );
    }
}

class WeatherConsoleSender implements CommandSender
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

final class WeatherPlayerSender extends WeatherConsoleSender implements PlayerCommandSender
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

    public function player(): Player
    {
        return $this->player;
    }
}
