<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSoftEnum;
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
        private CommandSoftEnum $itemIdentifiers,
        private ?Closure $giveItem = null,
        private ?Closure $itemExists = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'give',
            'Gives an item to an online player.',
            permission: 'bedriox.command.give',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::onlinePlayer('player'))
            ->addArgument(CommandParameter::softEnum('item', $this->itemIdentifiers))
            ->addArgument(CommandParameter::integer('amount')
                ->minimum(1)
                ->maximum(self::MAXIMUM_AMOUNT)
                ->optional(default: 1));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        $player = $values->player('player');
        $identifier = self::canonicalIdentifier($values->string('item'));
        if ($identifier === null || $this->itemExists === null || !($this->itemExists)($identifier)) {
            return CommandResult::failure('Unknown item.');
        }
        $amount = $values->integer('amount');
        if ($this->giveItem === null || !($this->giveItem)($player, $identifier, $amount)) {
            return CommandResult::failure('Unable to give the item.');
        }
        return CommandResult::success("Gave {$amount} {$identifier} to {$player->name}.");
    }

    private static function canonicalIdentifier(string $value): ?string
    {
        $identifier = strtolower(str_contains($value, ':') ? $value : 'minecraft:' . $value);

        return preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/D', $identifier) === 1 ? $identifier : null;
    }
}
