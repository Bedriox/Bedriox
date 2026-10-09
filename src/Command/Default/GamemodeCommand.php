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
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Bedriox\Api\TextFormat;
use Closure;

final readonly class GamemodeCommand implements BuiltinCommand
{
    /** @param Closure(Player, GameMode): bool|null $changeGameMode */
    public function __construct(private ?Closure $changeGameMode = null) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'gamemode',
            'Changes the game mode of an online player.',
            permission: 'bedriox.command.gamemode',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::choice('mode', [
                'survival', 'creative', 'adventure', 'spectator',
                '0', '1', '2', '3', 's', 'c', 'a', 'sp',
            ]))
            ->addArgument(CommandParameter::onlinePlayer('player')->optional());
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        $gameMode = self::parse($values->string('mode'));
        if ($gameMode === null) {
            return CommandResult::failure('Unknown game mode.');
        }
        $target = $values->has('player')
            ? $values->player('player')
            : ($context->sender() instanceof PlayerCommandSender ? $context->sender()->player() : null);
        if ($target === null) {
            return CommandResult::failure('A player target is required when running this command from the console.');
        }
        if ($target->getGameMode() === $gameMode) {
            return CommandResult::warning($target->uuid === ($context->sender() instanceof PlayerCommandSender
                ? $context->sender()->player()->uuid
                : null)
                ? 'You are already in ' . self::displayName($gameMode) . ' mode.'
                : "{$target->name} is already in " . self::displayName($gameMode) . ' mode.');
        }
        if ($this->changeGameMode === null || !($this->changeGameMode)($target, $gameMode)) {
            return CommandResult::failure('Unable to change the player game mode.');
        }
        $self = $context->sender() instanceof PlayerCommandSender
            && $context->sender()->player()->uuid === $target->uuid;
        if (!$self) {
            $target->sendMessage(
                TextFormat::GRAY . 'Your game mode has been changed to ' . self::displayName($gameMode) . '.' . TextFormat::RESET,
            );
        }

        return CommandResult::administrativeSuccess($self
            ? 'Set your game mode to ' . self::displayName($gameMode) . '.'
            : "Set {$target->name}'s game mode to " . self::displayName($gameMode) . '.');
    }

    public static function parse(string $value): ?GameMode
    {
        return match (strtolower($value)) {
            '0', 's', 'survival' => GameMode::SURVIVAL,
            '1', 'c', 'creative' => GameMode::CREATIVE,
            '2', 'a', 'adventure' => GameMode::ADVENTURE,
            '3', 'sp', 'spectator' => GameMode::SPECTATOR,
            default => null,
        };
    }

    private static function displayName(GameMode $gameMode): string
    {
        return ucfirst($gameMode->value);
    }
}
