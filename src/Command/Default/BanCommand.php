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
use Bedriox\Api\Player\Player;
use Bedriox\Server\Access\BanManager;
use Closure;

final readonly class BanCommand implements BuiltinCommand
{
    /** @param Closure(): list<Player> $players */
    public function __construct(private BanManager $bans, private Closure $players) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('ban', 'Bans a player from the server.', permission: 'bedriox.command.ban');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::string('player'))
            ->addArgument(CommandParameter::rawText('reason')->optional('Banned by an operator.'));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $name = $context->values()->string('player');
        $reason = $context->values()->rawText('reason');
        $player = null;
        foreach (($this->players)() as $candidate) {
            if (strcasecmp($candidate->name, $name) === 0) {
                $player = $candidate;
                break;
            }
        }
        $resolvedName = $player === null ? $name : $player->name;
        $resolvedUuid = $player === null ? null : $player->uuid;
        if (!$this->bans->banPlayer($resolvedName, $reason, $resolvedUuid)) {
            return CommandResult::failure('That player is already banned or the change was cancelled.');
        }
        $player?->kick(
            $reason,
            disconnectScreenMessage: 'You are banned from this server.' . "\n" . $reason,
            cause: PlayerKickCause::BAN,
            actor: $context->sender()->name(),
        );

        return CommandResult::administrativeSuccess('Banned ' . $resolvedName . ': ' . $reason);
    }
}
