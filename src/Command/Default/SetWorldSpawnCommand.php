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
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\World;
use Closure;

final readonly class SetWorldSpawnCommand implements BuiltinCommand
{
    /** @param Closure(World, BlockPosition): bool $change */
    public function __construct(private Closure $change) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('setworldspawn', 'Changes the spawn point of the current world.', permission: 'bedriox.command.setworldspawn');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create())
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::blockPosition('position')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        if (!$context->sender() instanceof PlayerCommandSender) {
            return CommandResult::failure('This command requires an in-world player sender.');
        }
        $player = $context->sender()->player();
        $world = $player->position->world;
        if ($world === null) {
            return CommandResult::failure('The player world is unavailable.');
        }
        $position = $context->values()->has('position')
            ? $context->values()->blockPosition('position')
            : new BlockPosition((int) floor($player->position->x), (int) floor($player->position->y), (int) floor($player->position->z));
        if ($position->y < -64 || $position->y > 319) {
            return CommandResult::failure('The world spawn Y coordinate must be between -64 and 319.');
        }

        return ($this->change)($world, $position)
            ? CommandResult::administrativeSuccess("Set the world spawn to {$position->x}, {$position->y}, {$position->z}.")
            : CommandResult::failure('Unable to change the world spawn.');
    }
}
