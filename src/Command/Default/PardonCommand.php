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
use Bedriox\Server\Access\BanManager;

final readonly class PardonCommand implements BuiltinCommand
{
    public function __construct(private BanManager $bans) {}
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('pardon', 'Removes a player ban.', permission: 'bedriox.command.pardon');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()->addArgument(CommandParameter::string('player'));
    }
    public function execute(CommandContext $context): CommandResult
    {
        $name = $context->values()->string('player');
        return $this->bans->pardonPlayer($name)
            ? CommandResult::success("Pardoned {$name}.")
            : CommandResult::failure('That player is not banned or the change was cancelled.');
    }
}
