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
use Bedriox\Api\Inventory\ItemStack;

final readonly class ClearCommand implements BuiltinCommand
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('clear', 'Removes items from a player inventory.', permission: 'bedriox.command.clear');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create())
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::onlinePlayer('player')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::string('item'))
                ->addArgument(CommandParameter::integer('maximumCount')->minimum(1)->maximum(2304)->optional(2304)));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $player = $context->values()->has('player')
            ? $context->values()->player('player')
            : ($context->sender() instanceof PlayerCommandSender ? $context->sender()->player() : null);
        if ($player === null) {
            return CommandResult::failure('A player target is required when running this command from the console.');
        }
        $inventory = $player->getInventory();
        if (!$context->values()->has('item')) {
            $removed = count(array_filter($inventory->getContents()));
            $inventory->clearAll();

            return CommandResult::administrativeSuccess("Cleared {$removed} occupied inventory slots from {$player->name}'s inventory.");
        }
        $identifier = strtolower($context->values()->string('item'));
        $identifier = str_contains($identifier, ':') ? $identifier : 'minecraft:' . $identifier;
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            return CommandResult::failure('Unknown item identifier.');
        }
        $remaining = $context->values()->integer('maximumCount');
        $removed = 0;
        $contents = $inventory->getContents();
        foreach ($contents as $slot => $stack) {
            if ($remaining === 0 || $stack === null || $stack->identifier !== $identifier) {
                continue;
            }
            $amount = min($remaining, $stack->count);
            $remaining -= $amount;
            $removed += $amount;
            $contents[$slot] = $amount === $stack->count ? null : new ItemStack(
                $stack->identifier,
                $stack->count - $amount,
                $stack->damage,
                $stack->nbt,
                $stack->auxValue,
            );
        }
        if ($removed > 0) {
            $inventory->setContents($contents);
        }

        return $removed > 0
            ? CommandResult::administrativeSuccess('Removed ' . $removed . ' ' . CommandDisplayName::identifier($identifier) . " from {$player->name}'s inventory.")
            : CommandResult::failure("{$player->name} does not have that item.");
    }
}
