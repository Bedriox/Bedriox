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

use Bedriox\Api\Command\{CommandArguments, CommandContext, CommandDefinition, CommandParameter, CommandResult};
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\World;
use Closure;

final readonly class SetBlockCommand implements BuiltinCommand
{
    /** @param Closure(BlockPosition, string, ?World): bool $setBlock */
    public function __construct(private Closure $setBlock) {}
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('setblock', 'Sets one block in the current world.', permission: 'bedriox.command.setblock');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()->addArgument(CommandParameter::blockPosition('position'))->addArgument(CommandParameter::string('block'));
    }
    public function execute(CommandContext $context): CommandResult
    {
        $id = strtolower($context->values()->string('block'));
        $id = str_contains($id, ':') ? $id : 'minecraft:' . $id;
        $world = $context->sender() instanceof \Bedriox\Api\Command\PlayerCommandSender ? $context->sender()->player()->position->world : null;
        return preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $id) === 1 && ($this->setBlock)($context->values()->blockPosition('position'), $id, $world)
            ? CommandResult::success('Block changed to ' . $id . '.') : CommandResult::failure('Unable to set that block.');
    }
}
