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
use Bedriox\Api\Player\GameMode;
use Closure;

final readonly class DefaultGameModeCommand implements BuiltinCommand
{
    /** @param Closure(): GameMode $current @param Closure(GameMode): bool $change */
    public function __construct(private Closure $current, private Closure $change) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('defaultgamemode', 'Changes the default game mode.', permission: 'bedriox.command.defaultgamemode');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()->addArgument(CommandParameter::choice('mode', [
            'survival', 'creative', 'adventure', 'spectator', '0', '1', '2', '3', 's', 'c', 'a', 'sp',
        ]));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $mode = GamemodeCommand::parse($context->values()->string('mode'));
        if ($mode === null) {
            return CommandResult::failure('Unknown game mode.');
        }
        if (($this->current)() === $mode) {
            return CommandResult::warning('The default game mode is already ' . ucfirst($mode->value) . '.');
        }

        return ($this->change)($mode)
            ? CommandResult::administrativeSuccess('Set the default game mode to ' . ucfirst($mode->value) . '.')
            : CommandResult::failure('Unable to change the default game mode.');
    }
}
