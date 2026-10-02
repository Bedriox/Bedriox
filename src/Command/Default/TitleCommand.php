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
use Bedriox\Api\Player\TitleTimes;

final readonly class TitleCommand implements BuiltinCommand
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('title', 'Controls player titles and action bars.', permission: 'bedriox.command.title');
    }
    public function defineArguments(): CommandArguments
    {
        $player = CommandParameter::onlinePlayer('player');
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()->addArgument($player)->addArgument(CommandParameter::literal('clear')))
            ->addOverload(CommandOverload::create()->addArgument($player)->addArgument(CommandParameter::literal('reset')))
            ->addOverload(CommandOverload::create()->addArgument($player)->addArgument(CommandParameter::literal('title'))->addArgument(CommandParameter::rawText('text')))
            ->addOverload(CommandOverload::create()->addArgument($player)->addArgument(CommandParameter::literal('subtitle'))->addArgument(CommandParameter::rawText('text')))
            ->addOverload(CommandOverload::create()->addArgument($player)->addArgument(CommandParameter::literal('actionbar'))->addArgument(CommandParameter::rawText('text')))
            ->addOverload(CommandOverload::create()->addArgument($player)->addArgument(CommandParameter::literal('times'))
                ->addArgument(CommandParameter::integer('fadeIn')->minimum(0)->maximum(12000))
                ->addArgument(CommandParameter::integer('stay')->minimum(0)->maximum(12000))
                ->addArgument(CommandParameter::integer('fadeOut')->minimum(0)->maximum(12000)));
    }
    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        $player = $values->player('player');
        $sent = match (true) {
            $values->has('clear') => $player->clearTitle(),
            $values->has('reset') => $player->resetTitles(),
            $values->has('title') => $player->sendTitle($values->rawText('text')),
            $values->has('subtitle') => $player->sendSubTitle($values->rawText('text')),
            $values->has('actionbar') => $player->sendActionBar($values->rawText('text')),
            $values->has('times') => $player->setTitleTimes(new TitleTimes($values->integer('fadeIn'), $values->integer('stay'), $values->integer('fadeOut'))),
            default => false,
        };
        return $sent ? CommandResult::success('Updated title presentation for ' . $player->name . '.') : CommandResult::failure('Unable to update that player title.');
    }
}
