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
use Bedriox\Api\World\World;
use Closure;

final readonly class SeedCommand implements BuiltinCommand
{
    /** @param Closure(?World): ?int $seed */
    public function __construct(private Closure $seed) {}
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('seed', 'Shows the world seed.', permission: 'bedriox.command.seed');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }
    public function execute(CommandContext $context): CommandResult
    {
        $world = $context->sender() instanceof \Bedriox\Api\Command\PlayerCommandSender ? $context->sender()->player()->position->world : null;
        $seed = ($this->seed)($world);
        return $seed === null ? CommandResult::failure('World seed is unavailable.') : CommandResult::success('Seed: ' . $seed);
    }
}
