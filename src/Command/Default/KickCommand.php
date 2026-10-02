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
use Bedriox\Api\Event\Player\PlayerKickCause;

final readonly class KickCommand implements BuiltinCommand
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('kick', 'Disconnects an online player.', permission: 'bedriox.command.kick');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::onlinePlayer('player'))
            ->addArgument(CommandParameter::message('reason')->optional('Kicked by an operator.'));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $player = $context->values()->player('player');
        $reason = trim($context->values()->message('reason'));
        $reason = $reason === '' ? 'Kicked by an operator.' : $reason;

        return $player->kick(
            $reason,
            disconnectScreenMessage: $reason,
            cause: PlayerKickCause::OPERATOR,
            actor: $context->sender()->name(),
        )
            ? CommandResult::success('Kicked ' . $player->name . '.')
            : CommandResult::failure('Unable to kick that player.');
    }
}
