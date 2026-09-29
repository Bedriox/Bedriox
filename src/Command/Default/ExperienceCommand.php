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
use Bedriox\Api\Player\ExperienceChangeCause;
use Bedriox\Server\Player\ExperienceMath;
use LogicException;

final readonly class ExperienceCommand implements BuiltinCommand
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'experience',
            'Queries or changes a player\'s experience.',
            ['xp'],
            'bedriox.command.experience',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('query'))
                ->addArgument(CommandParameter::onlinePlayer('player')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('set'))
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::integer('amount')->minimum(0)->maximum(ExperienceMath::MAXIMUM_TOTAL_POINTS))
                ->addArgument(CommandParameter::choice('unit', ['points', 'levels'])->optional('points')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('add'))
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::integer('amount')->minimum(-ExperienceMath::MAXIMUM_TOTAL_POINTS)->maximum(ExperienceMath::MAXIMUM_TOTAL_POINTS))
                ->addArgument(CommandParameter::choice('unit', ['points', 'levels'])->optional('points')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        $player = $values->player('player');
        $experience = $player->getExperience();
        $current = $experience->getSnapshot();
        if ($values->has('query')) {
            return CommandResult::success(sprintf(
                '%s has %d experience points (level %d, %.1f%% progress).',
                $player->name,
                $current->totalPoints,
                $current->level,
                $current->progress * 100.0,
            ));
        }

        $amount = $values->integer('amount');
        $unit = $values->choice('unit');
        try {
            if ($unit === 'levels') {
                $targetLevel = $values->has('set') ? $amount : max(0, $current->level + $amount);
                if ($targetLevel > ExperienceMath::MAXIMUM_LEVEL) {
                    return CommandResult::failure('The requested experience level exceeds the supported range.');
                }
                $points = ExperienceMath::totalPointsToReachLevel($targetLevel);
                $experience->setTotalPoints($points, ExperienceChangeCause::COMMAND);
            } else {
                $points = $values->has('set')
                    ? $amount
                    : max(0, min(ExperienceMath::MAXIMUM_TOTAL_POINTS, $current->totalPoints + $amount));
                $experience->setTotalPoints($points, ExperienceChangeCause::COMMAND);
            }
        } catch (LogicException) {
            return CommandResult::failure('The player is no longer available.');
        }

        return CommandResult::success("Set {$player->name}'s experience to {$points} points.");
    }
}
