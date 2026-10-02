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
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldDifficulty;
use Closure;

final readonly class DifficultyCommand implements BuiltinCommand
{
    /** @param Closure(?World): ?WorldDifficulty $current @param Closure(?World, WorldDifficulty): bool $change */
    public function __construct(private Closure $current, private Closure $change) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('difficulty', 'Gets or changes a world difficulty.', permission: 'bedriox.command.difficulty');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create())
            ->addOverload(CommandOverload::create()->addArgument(CommandParameter::choice('difficulty', [
                'peaceful', 'easy', 'normal', 'hard', '0', '1', '2', '3',
            ])));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $world = $context->sender() instanceof PlayerCommandSender ? $context->sender()->player()->position->world : null;
        if (!$context->values()->has('difficulty')) {
            $current = ($this->current)($world);

            return $current === null
                ? CommandResult::failure('The world difficulty is unavailable.')
                : CommandResult::success("The difficulty is {$current->value}.");
        }
        $difficulty = self::parse($context->values()->string('difficulty'));
        if ($difficulty === null) {
            return CommandResult::failure('Unknown difficulty.');
        }
        if (($this->current)($world) === $difficulty) {
            return CommandResult::success("The difficulty is already {$difficulty->value}.");
        }

        return ($this->change)($world, $difficulty)
            ? CommandResult::success("Set the difficulty to {$difficulty->value}.")
            : CommandResult::failure('Unable to change the difficulty.');
    }

    private static function parse(string $value): ?WorldDifficulty
    {
        return match (strtolower($value)) {
            '0', 'peaceful' => WorldDifficulty::PEACEFUL,
            '1', 'easy' => WorldDifficulty::EASY,
            '2', 'normal' => WorldDifficulty::NORMAL,
            '3', 'hard' => WorldDifficulty::HARD,
            default => null,
        };
    }
}
