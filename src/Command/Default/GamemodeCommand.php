<?php

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
        $gameMode = self::parseGameMode($values->string('mode'));
        if ($gameMode === null) {
            return CommandResult::failure('Unknown game mode.');
        }
        $target = $values->has('player')
            ? $values->player('player')
            : ($context->sender() instanceof PlayerCommandSender ? $context->sender()->player() : null);
        if ($target === null) {
            return CommandResult::failure('A player target is required when running this command from the console.');
        }
        if ($target->getGamemode() === $gameMode) {
            return CommandResult::success("{$target->name} is already in {$gameMode->value} mode.");
        }
        if ($this->changeGameMode === null || !($this->changeGameMode)($target, $gameMode)) {
            return CommandResult::failure('Unable to change the player game mode.');
        }
        return CommandResult::success("Set {$target->name}'s game mode to {$gameMode->value}.");
    }

    private static function parseGameMode(string $value): ?GameMode
    {
        return match (strtolower($value)) {
            '0', 's', 'survival' => GameMode::SURVIVAL,
            '1', 'c', 'creative' => GameMode::CREATIVE,
            '2', 'a', 'adventure' => GameMode::ADVENTURE,
            '3', 'sp', 'spectator' => GameMode::SPECTATOR,
            default => null,
        };
    }
}
