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
use Bedriox\Api\Whitelist\Whitelist;
use Closure;

final readonly class WhitelistCommand implements BuiltinCommand
{
    /** @param null|Closure(): void $enforce */
    public function __construct(private Whitelist $whitelist, private ?Closure $enforce = null) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('whitelist', 'Manages server whitelist access.', permission: 'bedriox.command.whitelist');
    }

    public function defineArguments(): CommandArguments
    {
        $arguments = CommandArguments::create()->addOverload(CommandOverload::create());
        foreach (['status', 'on', 'off', 'list', 'reload'] as $action) {
            $arguments = $arguments->addOverload(CommandOverload::create()->addArgument(CommandParameter::literal($action)));
        }
        foreach (['add', 'remove'] as $action) {
            $arguments = $arguments->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal($action))
                ->addArgument(CommandParameter::string('player')));
        }
        return $arguments;
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        if ($values->all() === []) {
            return CommandResult::success('Whitelist is ' . ($this->whitelist->isEnabled() ? 'enabled.' : 'disabled.'));
        }
        foreach (['status', 'on', 'off', 'list', 'add', 'remove', 'reload'] as $action) {
            if (!$values->has($action)) {
                continue;
            }
            if (!$context->sender()->hasPermission('bedriox.command.whitelist.' . $action)) {
                return CommandResult::failure('You do not have permission to use this whitelist action.');
            }
            return match ($action) {
                'status' => CommandResult::success('Whitelist is ' . ($this->whitelist->isEnabled() ? 'enabled.' : 'disabled.')),
                'on' => $this->toggle(true),
                'off' => $this->toggle(false),
                'list' => $this->list(),
                'add' => CommandResult::success($this->whitelist->add($values->string('player'))
                    ? 'Player added to the whitelist.' : 'Player is already whitelisted.'),
                'remove' => CommandResult::success($this->whitelist->remove($values->string('player'))
                    ? 'Player removed from the whitelist.' : 'Player was not whitelisted.'),
                'reload' => $this->reload(),
            };
        }
        return CommandResult::failure('Choose a whitelist action.');
    }

    private function toggle(bool $enabled): CommandResult
    {
        $changed = $this->whitelist->setEnabled($enabled);
        if ($enabled && $this->enforce !== null) {
            ($this->enforce)();
        }
        return CommandResult::success($changed ? 'Whitelist setting updated.' : 'Whitelist setting was already unchanged.');
    }

    private function list(): CommandResult
    {
        $names = array_map(static fn($entry): string => $entry->lastKnownName, $this->whitelist->entries());
        return CommandResult::success($names === [] ? 'The whitelist is empty.' : 'Whitelisted players: ' . implode(', ', $names));
    }

    private function reload(): CommandResult
    {
        $this->whitelist->reload();
        if ($this->whitelist->isEnabled() && $this->enforce !== null) {
            ($this->enforce)();
        }
        return CommandResult::success('Whitelist reloaded.');
    }
}
