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
use Bedriox\Api\Command\CommandResult;
use Closure;

final readonly class SaveToggleCommand implements BuiltinCommand
{
    /** @param Closure(bool): bool $change */
    public function __construct(private bool $enabled, private Closure $change) {}

    public function definition(): CommandDefinition
    {
        $name = $this->enabled ? 'save-on' : 'save-off';

        return new CommandDefinition($name, ($this->enabled ? 'Enables' : 'Disables') . ' automatic world and player saving.', permission: 'bedriox.command.save');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }

    public function execute(CommandContext $context): CommandResult
    {
        return ($this->change)($this->enabled)
            ? CommandResult::administrativeSuccess('Automatic saving is now ' . ($this->enabled ? 'enabled.' : 'disabled.'))
            : CommandResult::warning('Automatic saving is already ' . ($this->enabled ? 'enabled.' : 'disabled.'));
    }
}
