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
use Closure;

final readonly class BanIpCommand implements BuiltinCommand
{
    /** @param Closure(string): ?string $resolveAddress @param Closure(string, string, string): int $kickAddress */
    public function __construct(private BanManager $bans, private Closure $resolveAddress, private Closure $kickAddress) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('ban-ip', 'Bans an IPv4 address from the server.', permission: 'bedriox.command.ban-ip');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::string('target'))
            ->addArgument(CommandParameter::rawText('reason')->optional('Banned by an operator.'));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $target = $context->values()->string('target');
        $address = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            ? ($this->resolveAddress)($target)
            : $target;
        if ($address === null) {
            return CommandResult::failure('Provide an IPv4 address or an online player name.');
        }
        $reason = $context->values()->rawText('reason');
        if (!$this->bans->banAddress($address, $reason)) {
            return CommandResult::failure('That address is already banned or the change was cancelled.');
        }
        $kicked = ($this->kickAddress)($address, $reason, $context->sender()->name());
        if (!is_int($kicked)) {
            throw new \UnexpectedValueException('Address kick callback must return an integer.');
        }

        return CommandResult::administrativeSuccess("Banned {$address}; disconnected {$kicked} matching player(s).");
    }
}
