<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\World\WorldTimePreset;
use Bedriox\Server\World\WorldTimeRules;
use Closure;

final readonly class TimeCommand implements BuiltinCommand
{
    /**
     * @param Closure(): (int|null)     $currentTime
     * @param Closure(int): (int|null)  $setTime
     * @param Closure(int): (int|null)  $addTime
     * @param Closure(bool): (int|null) $setRunning
     */
    public function __construct(
        private Closure $currentTime,
        private Closure $setTime,
        private Closure $addTime,
        private Closure $setRunning,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'time',
            'Queries or changes the current world time.',
            permission: 'bedriox.command.time',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('set'))
                ->addArgument(CommandParameter::enum('preset', WorldTimePreset::class)))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('set'))
                ->addArgument(CommandParameter::integer('ticks')
                    ->minimum(WorldTimeRules::MINIMUM)
                    ->maximum(WorldTimeRules::MAXIMUM)))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('add'))
                ->addArgument(CommandParameter::integer('amount')
                    ->minimum(WorldTimeRules::MINIMUM)
                    ->maximum(WorldTimeRules::MAXIMUM)))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('query')))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('start')))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('stop')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        if ($values->has('preset')) {
            /** @var WorldTimePreset $preset */
            $preset = $values->enum('preset', WorldTimePreset::class);
            $time = ($this->setTime)($preset->ticks());

            return $time === null
                ? CommandResult::failure('World time is unavailable.')
                : CommandResult::success("Set the world time to {$time} ({$preset->value}).");
        }
        if ($values->has('ticks')) {
            $time = ($this->setTime)($values->integer('ticks'));

            return $time === null
                ? CommandResult::failure('World time is unavailable.')
                : CommandResult::success("Set the world time to {$time}.");
        }
        if ($values->has('amount')) {
            $amount = $values->integer('amount');
            $time = ($this->addTime)($amount);

            return $time === null
                ? CommandResult::failure('World time is unavailable.')
                : CommandResult::success("Added {$amount} ticks. The world time is now {$time}.");
        }
        if ($values->has('start')) {
            $time = ($this->setRunning)(true);

            return $time === null
                ? CommandResult::failure('World time is unavailable.')
                : CommandResult::success("The daylight cycle is running from time {$time}.");
        }
        if ($values->has('stop')) {
            $time = ($this->setRunning)(false);

            return $time === null
                ? CommandResult::failure('World time is unavailable.')
                : CommandResult::success("The daylight cycle is stopped at time {$time}.");
        }

        $time = ($this->currentTime)();
        if ($time === null) {
            return CommandResult::failure('World time is unavailable.');
        }

        return CommandResult::success(sprintf(
            'World time is %d (day %d, daytime %d).',
            $time,
            WorldTimeRules::day($time),
            WorldTimeRules::timeOfDay($time),
        ));
    }
}
