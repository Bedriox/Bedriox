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
use Bedriox\Server\Access\BanManager;

final readonly class PardonIpCommand implements BuiltinCommand
{
    public function __construct(private BanManager $bans) {}
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('pardon-ip', 'Removes an IPv4 address ban.', permission: 'bedriox.command.pardon-ip');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()->addArgument(CommandParameter::string('address'));
    }
    public function execute(CommandContext $context): CommandResult
    {
        $address = $context->values()->string('address');
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return CommandResult::failure('A valid IPv4 address is required.');
        }
        return $this->bans->pardonAddress($address)
            ? CommandResult::administrativeSuccess("Pardoned {$address}.")
            : CommandResult::failure('That address is not banned or the change was cancelled.');
    }
}
