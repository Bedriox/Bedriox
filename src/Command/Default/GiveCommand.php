<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Player\Player;
use Closure;

final readonly class GiveCommand implements BuiltinCommand
{
    private const int MAXIMUM_AMOUNT = 32_767;

    /**
     * @param Closure(Player, string, int): bool|null $giveItem
     * @param Closure(string): bool|null $itemExists
     */
    public function __construct(
        private OnlinePlayerResolver $players,
        private ?Closure $giveItem = null,
        private ?Closure $itemExists = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'give',
            'Gives an item to an online player.',
            'give <player> <item> [amount]',
            permission: 'bedriox.command.give',
        );
    }

    public function execute(CommandContext $context): CommandResult
    {
        $arguments = $context->arguments();
        if (count($arguments) < 2 || count($arguments) > 3) {
            return CommandResult::USAGE;
        }
        $player = $this->players->find($arguments[0]);
        if ($player === null) {
            $context->sender()->sendMessage('Player is not online.');

            return CommandResult::FAILURE;
        }
        $identifier = self::canonicalIdentifier($arguments[1]);
        if ($identifier === null || $this->itemExists === null || !($this->itemExists)($identifier)) {
            $context->sender()->sendMessage('Unknown item.');

            return CommandResult::FAILURE;
        }
        $amount = count($arguments) === 3 ? self::parseAmount($arguments[2]) : 1;
        if ($amount === null) {
            $context->sender()->sendMessage('Amount must be a whole number between 1 and ' . self::MAXIMUM_AMOUNT . '.');

            return CommandResult::FAILURE;
        }
        if ($this->giveItem === null || !($this->giveItem)($player, $identifier, $amount)) {
            $context->sender()->sendMessage('Unable to give the item.');

            return CommandResult::FAILURE;
        }
        $context->sender()->sendMessage("Gave {$amount} {$identifier} to {$player->name}.");

        return CommandResult::SUCCESS;
    }

    private static function canonicalIdentifier(string $value): ?string
    {
        $identifier = strtolower(str_contains($value, ':') ? $value : 'minecraft:' . $value);

        return preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/D', $identifier) === 1 ? $identifier : null;
    }

    private static function parseAmount(string $value): ?int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || strlen($value) > 5) {
            return null;
        }
        $amount = (int) $value;

        return $amount <= self::MAXIMUM_AMOUNT ? $amount : null;
    }
}
