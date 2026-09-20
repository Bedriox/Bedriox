<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Server\Permission\PermissionStore;
use Closure;

abstract readonly class OperatorCommand implements BuiltinCommand
{
    public function __construct(
        private PermissionStore $permissions,
        private OnlinePlayerResolver $players,
        private bool $operator,
        private ?Closure $authorityChanged = null,
    ) {}

    final public function execute(CommandContext $context): CommandResult
    {
        $arguments = $context->arguments();
        if (count($arguments) !== 1) {
            return CommandResult::USAGE;
        }
        $player = $this->players->find($arguments[0]);
        if ($player === null) {
            $context->sender()->sendMessage('Player is not online.');

            return CommandResult::FAILURE;
        }
        $changed = $this->permissions->setOperator($player->uuid, $player->name, $this->operator);
        if ($changed && $this->authorityChanged !== null) {
            ($this->authorityChanged)($player, true);
        }
        $context->sender()->sendMessage($changed
            ? ($this->operator ? "{$player->name} is now an operator." : "{$player->name} is no longer an operator.")
            : ($this->operator ? "{$player->name} is already an operator." : "{$player->name} is not an operator."));

        return CommandResult::SUCCESS;
    }
}
