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
use Bedriox\Api\Player\Player;
use Closure;

final readonly class EnchantCommand implements BuiltinCommand
{
    /** @param Closure(Player, string, int): bool $enchant */
    public function __construct(private Closure $enchant) {}
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('enchant', 'Adds an enchantment to the selected item.', permission: 'bedriox.command.enchant');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()->addArgument(CommandParameter::onlinePlayer('player'))->addArgument(CommandParameter::string('enchantment'))->addArgument(CommandParameter::integer('level')->minimum(1)->maximum(255)->optional(1));
    }
    public function execute(CommandContext $context): CommandResult
    {
        $player = $context->values()->player('player');
        $id = strtolower($context->values()->string('enchantment'));
        $id = str_contains($id, ':') ? $id : 'minecraft:' . $id;
        $level = $context->values()->integer('level');
        return ($this->enchant)($player, $id, $level)
            ? CommandResult::administrativeSuccess(
                "Enchanted {$player->name}'s selected item with " . CommandDisplayName::identifier($id) . " {$level}.",
            )
            : CommandResult::failure('The enchantment is unknown, incompatible, conflicting, or above its maximum level.');
    }
}
