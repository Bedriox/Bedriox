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

use Bedriox\Api\Command\{CommandArguments, CommandContext, CommandDefinition, CommandResult};
use Closure;

final readonly class SaveCommand implements BuiltinCommand
{
    /** @param Closure(): int $save */
    public function __construct(private Closure $save) {}
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('save-all', 'Saves every loaded world.', aliases: ['save'], permission: 'bedriox.command.save');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }
    public function execute(CommandContext $context): CommandResult
    {
        $count = ($this->save)();
        return CommandResult::success("Saved {$count} loaded worlds.");
    }
}
