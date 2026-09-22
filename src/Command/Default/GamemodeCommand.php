<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Closure;

final readonly class GamemodeCommand implements BuiltinCommand
{
    /** @param Closure(Player, GameMode): bool|null $changeGameMode */
    public function __construct(
        private OnlinePlayerResolver $players,
        private ?Closure $changeGameMode = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'gamemode',
            'Changes the game mode of an online player.',
            'gamemode <survival|creative|adventure|spectator> [player]',
            permission: 'bedriox.command.gamemode',
        );
    }

    public function execute(CommandContext $context): CommandResult
    {
        $arguments = $context->arguments();
        if (count($arguments) < 1 || count($arguments) > 2) {
            return CommandResult::USAGE;
        }
        $gameMode = self::parseGameMode($arguments[0]);
        if ($gameMode === null) {
            $context->sender()->sendMessage('Unknown game mode.');

            return CommandResult::FAILURE;
        }
        $target = count($arguments) === 2
            ? $this->players->find($arguments[1])
            : ($context->sender() instanceof PlayerCommandSender ? $context->sender()->player() : null);
        if ($target === null) {
            if (count($arguments) === 1 && !$context->sender() instanceof PlayerCommandSender) {
                return CommandResult::USAGE;
            }
            $context->sender()->sendMessage('Player is not online.');

            return CommandResult::FAILURE;
        }
        if ($target->getGamemode() === $gameMode) {
            $context->sender()->sendMessage("{$target->name} is already in {$gameMode->value} mode.");

            return CommandResult::SUCCESS;
        }
        if ($this->changeGameMode === null || !($this->changeGameMode)($target, $gameMode)) {
            $context->sender()->sendMessage('Unable to change the player game mode.');

            return CommandResult::FAILURE;
        }
        $context->sender()->sendMessage("Set {$target->name}'s game mode to {$gameMode->value}.");

        return CommandResult::SUCCESS;
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
