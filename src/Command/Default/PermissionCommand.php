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
use Bedriox\Server\Permission\PermissionStore;
use Closure;

final readonly class PermissionCommand implements BuiltinCommand
{
    public function __construct(
        private PermissionStore $permissions,
        private ?Closure $authorityChanged = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'permission',
            'Views or changes an online player permission assignment.',
            aliases: ['perm'],
            permission: 'bedriox.command.permission',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('list'))
                ->addArgument(CommandParameter::onlinePlayer('player')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('grant'))
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::string('node')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('revoke'))
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::string('node')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        $player = $values->player('player');
        if ($values->has('list')) {
            $grants = $this->permissions->grants($player->uuid);
            return CommandResult::success("Permissions for {$player->name}: " . ($grants === [] ? 'none' : implode(', ', $grants)));
        }
        $changed = $values->has('grant')
            ? $this->permissions->grant($player->uuid, $player->name, $values->string('node'))
            : $this->permissions->revoke($player->uuid, $values->string('node'));
        if ($changed && $this->authorityChanged !== null) {
            ($this->authorityChanged)($player, false);
        }
        return CommandResult::success($changed ? 'Permission assignment updated.' : 'Permission assignment was already unchanged.');
    }
}
