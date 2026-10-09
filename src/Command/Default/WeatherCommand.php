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

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use Bedriox\Api\World\World;
use Closure;

final readonly class WeatherCommand implements BuiltinCommand
{
    /**
     * @param Closure(?World): (WeatherState|null)                    $currentWeather
     * @param Closure(?World, WeatherType, ?int): (WeatherState|null) $setWeather
     */
    public function __construct(
        private Closure $currentWeather,
        private Closure $setWeather,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'weather',
            'Queries or changes the current world weather.',
            permission: 'bedriox.command.weather',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::enum('type', WeatherType::class))
                ->addArgument(CommandParameter::integer('durationSeconds')
                    ->minimum(WeatherState::MINIMUM_COMMAND_DURATION_SECONDS)
                    ->maximum(WeatherState::MAXIMUM_COMMAND_DURATION_SECONDS)
                    ->optional()))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal('query')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $world = self::world($context);
        $values = $context->values();
        if ($values->has('type')) {
            /** @var WeatherType $type */
            $type = $values->enum('type', WeatherType::class);
            $durationSeconds = $values->has('durationSeconds') ? $values->integer('durationSeconds') : null;
            $weather = ($this->setWeather)($world, $type, $durationSeconds);
            if ($weather === null) {
                return CommandResult::failure('World weather is unavailable.');
            }

            return CommandResult::administrativeSuccess($durationSeconds === null
                ? 'Set the weather to ' . ucfirst($weather->type->value) . '.'
                : 'Set the weather to ' . ucfirst($weather->type->value) . " for {$durationSeconds} seconds.");
        }

        $weather = ($this->currentWeather)($world);
        if ($weather === null) {
            return CommandResult::failure('World weather is unavailable.');
        }

        return CommandResult::information(sprintf(
            'The weather is %s with %d seconds remaining.',
            ucfirst($weather->type->value),
            intdiv($weather->remainingTicks, WeatherState::TICKS_PER_SECOND),
        ));
    }

    private static function world(CommandContext $context): ?World
    {
        $sender = $context->sender();

        return $sender instanceof PlayerCommandSender ? $sender->player()->position->world : null;
    }
}
