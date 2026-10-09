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
use Bedriox\Server\Access\BanEntry;
use Bedriox\Server\Access\BanManager;

final readonly class BanListCommand implements BuiltinCommand
{
    public function __construct(private BanManager $bans) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('banlist', 'Lists banned players or IP addresses.', permission: 'bedriox.command.banlist');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create())
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::choice('list', ['players', 'ips'])));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $ips = $context->values()->has('list') && $context->values()->string('list') === 'ips';
        $entries = $ips ? $this->bans->addressBans() : $this->bans->playerBans();
        $names = array_map(static fn(BanEntry $entry): string => $entry->target, $entries);

        return CommandResult::information($names === []
            ? 'There are no banned ' . ($ips ? 'IP addresses.' : 'players.')
            : 'Banned ' . ($ips ? 'IP addresses' : 'players') . ' (' . count($names) . '): ' . implode(', ', $names));
    }
}
