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
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Player\Player;
use Closure;

final readonly class KillCommand implements BuiltinCommand
{
    /** @param Closure(Player|Entity): bool $kill */
    public function __construct(private Closure $kill) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'kill',
            'Kills entities selected by name or target selector.',
            aliases: ['suicide'],
            permission: 'bedriox.command.kill',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create())
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::entities('targets')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $senderPlayer = $context->sender() instanceof PlayerCommandSender
            ? $context->sender()->player()
            : null;
        $explicit = $context->values()->has('targets');
        if (!$explicit && $senderPlayer === null) {
            return CommandResult::failure('A target is required when running this command from the console.');
        }
        $targets = $explicit ? $context->values()->entities('targets') : [$senderPlayer];
        $selfOnly = $senderPlayer !== null && count($targets) === 1
            && $targets[0] instanceof Player
            && hash_equals($senderPlayer->uuid, $targets[0]->uuid);
        $permission = $selfOnly ? 'bedriox.command.kill.self' : 'bedriox.command.kill.other';
        if (!$context->sender()->hasPermission($permission)) {
            return CommandResult::failure($selfOnly
                ? 'You do not have permission to kill yourself.'
                : 'You do not have permission to kill other entities.');
        }

        $accepted = [];
        foreach ($targets as $target) {
            if (($this->kill)($target)) {
                $accepted[] = self::displayName($target);
            }
        }
        if ($accepted === []) {
            return CommandResult::failure('None of the selected entities could be killed.');
        }
        if (count($accepted) <= 5) {
            return CommandResult::success('Kill requested for ' . implode(', ', $accepted) . '.');
        }

        return CommandResult::success(sprintf('Kill requested for %d entities.', count($accepted)));
    }

    private static function displayName(Player|Entity $target): string
    {
        if ($target instanceof Player) {
            return $target->name;
        }
        $identifier = $target->getType()->identifier();
        $name = str_contains($identifier, ':') ? substr($identifier, strpos($identifier, ':') + 1) : $identifier;

        return $name . ' #' . $target->getRuntimeId();
    }
}
