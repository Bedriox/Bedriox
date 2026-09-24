<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Server\Permission\PermissionStore;
use Closure;

abstract readonly class OperatorCommand implements BuiltinCommand
{
    public function __construct(
        private PermissionStore $permissions,
        private bool $operator,
        private ?Closure $authorityChanged = null,
    ) {}

    final public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::onlinePlayer('player'));
    }

    final public function execute(CommandContext $context): CommandResult
    {
        $player = $context->values()->player('player');
        $changed = $this->permissions->setOperator($player->uuid, $player->name, $this->operator);
        if ($changed && $this->authorityChanged !== null) {
            ($this->authorityChanged)($player, true);
        }
        return CommandResult::success($changed
            ? ($this->operator ? "{$player->name} is now an operator." : "{$player->name} is no longer an operator.")
            : ($this->operator ? "{$player->name} is already an operator." : "{$player->name} is not an operator."));
    }
}
