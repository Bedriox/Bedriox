<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Command;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\World\WorldTimePreset;
use Bedriox\Server\Command\Default\TimeCommand;
use PHPUnit\Framework\TestCase;

final class TimeCommandTest extends TestCase
{
    public function testNamedNumericAndAddedTimeUseBoundedAuthoritativeCallbacks(): void
    {
        $time = 0;
        $command = self::command($time);
        $sender = new TimeCommandSender();

        $named = $command->execute(new CommandContext(
            $sender,
            'time',
            new CommandValues(['preset' => WorldTimePreset::NIGHT]),
        ));
        self::assertTrue($named->isSuccess());
        self::assertSame(13_000, $time);
        self::assertSame('Set the world time to 13000 (night).', $named->message());

        $numeric = $command->execute(new CommandContext(
            $sender,
            'time',
            new CommandValues(['ticks' => 23_000]),
        ));
        self::assertTrue($numeric->isSuccess());
        self::assertSame(23_000, $time);

        $added = $command->execute(new CommandContext(
            $sender,
            'time',
            new CommandValues(['amount' => 1_000]),
        ));
        self::assertTrue($added->isSuccess());
        self::assertSame(24_000, $time);
        self::assertSame('Added 1000 ticks. The world time is now 24000.', $added->message());
    }

    public function testQueryStartAndStopExposeTheWorldOwnedCycle(): void
    {
        $time = 49_000;
        $runningTransitions = [];
        $command = self::command($time, $runningTransitions);
        $sender = new TimeCommandSender();

        $query = $command->execute(new CommandContext(
            $sender,
            'time',
            new CommandValues(['query' => 'query']),
        ));
        self::assertTrue($query->isSuccess());
        self::assertSame('World time is 49000 (day 2, daytime 1000).', $query->message());

        $stop = $command->execute(new CommandContext(
            $sender,
            'time',
            new CommandValues(['stop' => 'stop']),
        ));
        self::assertTrue($stop->isSuccess());

        $start = $command->execute(new CommandContext(
            $sender,
            'time',
            new CommandValues(['start' => 'start']),
        ));
        self::assertTrue($start->isSuccess());
        self::assertSame([false, true], $runningTransitions);
    }

    public function testSchemaUsesTypedPresetAndBoundedIntegerOverloads(): void
    {
        $time = 0;
        $command = self::command($time);

        self::assertSame('bedriox.command.time', $command->definition()->permission);
        self::assertSame([
            '/time set <preset:day|noon|sunset|night|midnight|sunrise>',
            '/time set <ticks>',
            '/time add <amount>',
            '/time query',
            '/time start',
            '/time stop',
        ], $command->defineArguments()->usage('time'));
    }

    /** @param list<bool> $runningTransitions */
    private static function command(int &$time, array &$runningTransitions = []): TimeCommand
    {
        return new TimeCommand(
            static function () use (&$time): int {
                return $time;
            },
            static function (int $replacement) use (&$time): int {
                return $time = $replacement;
            },
            static function (int $amount) use (&$time): int {
                return $time += $amount;
            },
            static function (bool $replacement) use (&$runningTransitions, &$time): int {
                $runningTransitions[] = $replacement;

                return $time;
            },
        );
    }
}

final class TimeCommandSender implements CommandSender
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
