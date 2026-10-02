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
use Closure;

final readonly class MeCommand implements BuiltinCommand
{
    /** @param Closure(string): int $broadcast */
    public function __construct(private Closure $broadcast) {}
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('me', 'Broadcasts an action in the third person.', permission: 'bedriox.command.me');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()->addArgument(CommandParameter::message('action'));
    }
    public function execute(CommandContext $context): CommandResult
    {
        ($this->broadcast)('* ' . $context->sender()->name() . ' ' . $context->values()->message('action'));
        return CommandResult::success();
    }
}
