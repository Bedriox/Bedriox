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
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Player\Player;
use Bedriox\Api\TextFormat;
use Bedriox\Server\Command\CommandFeedback;

final readonly class ListCommand implements BuiltinCommand
{
    public function __construct(private OnlinePlayerResolver $players) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('list', 'Lists connected players.');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }

    public function execute(CommandContext $context): CommandResult
    {
        $names = array_map(static fn(Player $player): string => $player->name, $this->players->all());
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $count = count($names);
        $context->sender()->sendMessage(CommandFeedback::line(
            $context->sender(),
            TextFormat::GREEN,
            $count === 1 ? 'There is 1 player online.' : "There are {$count} players online.",
        ));
        $context->sender()->sendMessage(CommandFeedback::line(
            $context->sender(),
            TextFormat::AQUA,
            'Players: ' . ($names === [] ? 'none' : implode(', ', $names)),
        ));

        return CommandResult::success();
    }
}
