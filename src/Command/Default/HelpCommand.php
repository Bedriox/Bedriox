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
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\TextFormat;
use Bedriox\Server\Command\CommandFeedback;
use Bedriox\Server\Plugin\Command\CommandRegistry;

final readonly class HelpCommand implements BuiltinCommand
{
    public function __construct(private CommandRegistry $commands) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('help', 'Lists commands available to you.', aliases: ['commands']);
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create())
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::integer('page')->minimum(1)))
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::string('command')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $sender = $context->sender();
        $commands = $this->commands->availableCommands($sender->type(), $sender->hasPermission(...));
        usort($commands, static fn($left, $right): int => $left->definition->name <=> $right->definition->name);
        if ($context->values()->has('command')) {
            $label = strtolower(ltrim($context->values()->string('command'), '/'));
            foreach ($commands as $command) {
                if ($command->definition->name !== $label && !in_array($label, $command->definition->aliases, true)) {
                    continue;
                }
                $sender->sendMessage(CommandFeedback::header($sender, '--------- Help: /' . $command->definition->name . ' ---------'));
                $sender->sendMessage(CommandFeedback::normal($sender, $command->definition->description));
                foreach ($command->arguments->usage($command->definition->name) as $usage) {
                    $sender->sendMessage(CommandFeedback::formatted(
                        $sender,
                        TextFormat::GOLD . 'Usage: ' . TextFormat::WHITE . $usage,
                        'Usage: ' . $usage,
                    ));
                }
                if ($command->definition->aliases !== []) {
                    $sender->sendMessage(CommandFeedback::formatted(
                        $sender,
                        TextFormat::GOLD . 'Aliases: ' . TextFormat::WHITE . implode(', ', $command->definition->aliases),
                        'Aliases: ' . implode(', ', $command->definition->aliases),
                    ));
                }

                return CommandResult::success();
            }

            return CommandResult::failure('No help is available for that command.');
        }

        $perPage = $sender->type() === CommandSenderType::CONSOLE ? max(1, count($commands)) : 7;
        $pages = max(1, (int) ceil(count($commands) / $perPage));
        $page = min($context->values()->has('page') ? $context->values()->integer('page') : 1, $pages);
        $sender->sendMessage(CommandFeedback::header($sender, "--------- Commands ({$page}/{$pages}) ---------"));
        foreach (array_slice($commands, ($page - 1) * $perPage, $perPage) as $command) {
            $line = '/' . $command->definition->name . ' - ' . $command->definition->description;
            $sender->sendMessage(CommandFeedback::formatted(
                $sender,
                TextFormat::DARK_GREEN . '/' . $command->definition->name . TextFormat::WHITE . ' - ' . $command->definition->description,
                $line,
            ));
        }
        if ($page < $pages) {
            $sender->sendMessage(CommandFeedback::normal($sender, 'Use /help ' . ($page + 1) . ' to view the next page.'));
        }

        return CommandResult::success();
    }
}
