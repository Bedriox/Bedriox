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
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Closure;

final readonly class SpawnPointCommand implements BuiltinCommand
{
    /** @param Closure(Player, Position): bool $change */
    public function __construct(private Closure $change) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('spawnpoint', 'Sets a player personal spawn point.', permission: 'bedriox.command.spawnpoint');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create())
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::onlinePlayer('player')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::position('position')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $target = $context->values()->has('player')
            ? $context->values()->player('player')
            : ($context->sender() instanceof PlayerCommandSender ? $context->sender()->player() : null);
        if ($target === null || $target->position->world === null) {
            return CommandResult::failure('A player target with a loaded world is required.');
        }
        $position = $context->values()->has('position')
            ? $context->values()->position('position')
            : new Position($target->position->x, $target->position->y, $target->position->z, world: $target->position->world);

        return ($this->change)($target, $position)
            ? CommandResult::administrativeSuccess(sprintf(
                "Set %s's spawn point to %.2f, %.2f, %.2f.",
                $target->name,
                $position->x,
                $position->y,
                $position->z,
            ))
            : CommandResult::failure('Unable to change that spawn point.');
    }
}
