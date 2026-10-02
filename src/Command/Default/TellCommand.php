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
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\TextFormat;
use Bedriox\Server\Command\CommandFeedback;

final readonly class TellCommand implements BuiltinCommand
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('tell', 'Sends a private message.', aliases: ['msg', 'w'], permission: 'bedriox.command.tell');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()->addArgument(CommandParameter::onlinePlayer('player'))->addArgument(CommandParameter::message('message'));
    }
    public function execute(CommandContext $context): CommandResult
    {
        $target = $context->values()->player('player');
        if (strcasecmp($target->name, $context->sender()->name()) === 0) {
            return CommandResult::failure('You cannot send a private message to yourself.');
        }
        $message = $context->values()->message('message');
        if (!$target->sendMessage(TextFormat::GRAY . TextFormat::ITALIC . '[' . $context->sender()->name() . ' -> you] ' . $message . TextFormat::RESET)) {
            return CommandResult::failure('The message could not be delivered.');
        }
        $context->sender()->sendMessage(CommandFeedback::formatted(
            $context->sender(),
            TextFormat::GRAY . TextFormat::ITALIC . '[you -> ' . $target->name . '] ' . $message,
            '[you -> ' . $target->name . '] ' . $message,
        ));
        return CommandResult::success();
    }
}
