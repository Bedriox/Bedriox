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
use Bedriox\Api\TextFormat;
use Bedriox\Server\Command\CommandFeedback;
use Closure;

final readonly class PluginsCommand implements BuiltinCommand
{
    /** @param Closure(): array<string, bool> $plugins */
    public function __construct(private Closure $plugins) {}
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('plugins', 'Lists loaded plugins.', aliases: ['pl'], permission: 'bedriox.command.plugins');
    }
    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }
    public function execute(CommandContext $context): CommandResult
    {
        $plugins = ($this->plugins)();
        ksort($plugins, SORT_NATURAL | SORT_FLAG_CASE);
        $context->sender()->sendMessage(CommandFeedback::normal($context->sender(), 'Plugins (' . count($plugins) . '):'));
        $plain = [];
        $colored = [];
        foreach ($plugins as $name => $enabled) {
            $plain[] = $name;
            $colored[] = ($enabled ? TextFormat::GREEN : TextFormat::RED) . $name;
        }
        if ($plain === []) {
            $context->sender()->sendMessage(CommandFeedback::normal($context->sender(), 'No plugins are loaded.'));
            return CommandResult::success();
        }
        $context->sender()->sendMessage(CommandFeedback::formatted($context->sender(), implode(TextFormat::RESET . ', ', $colored), implode(', ', $plain)));
        return CommandResult::success();
    }
}
