<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Server\Permission\PermissionStore;
use Closure;

final readonly class PermissionCommand implements BuiltinCommand
{
    public function __construct(
        private PermissionStore $permissions,
        private OnlinePlayerResolver $players,
        private ?Closure $authorityChanged = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'permission',
            'Views or changes an online player permission assignment.',
            'permission <list|grant|revoke> <player> [node]',
            aliases: ['perm'],
            permission: 'bedriox.command.permission',
        );
    }

    public function execute(CommandContext $context): CommandResult
    {
        $arguments = $context->arguments();
        if (count($arguments) < 2 || count($arguments) > 3) {
            return CommandResult::USAGE;
        }
        $operation = strtolower($arguments[0]);
        $player = $this->players->find($arguments[1]);
        if ($player === null) {
            $context->sender()->sendMessage('Player is not online.');

            return CommandResult::FAILURE;
        }
        if ($operation === 'list' && count($arguments) === 2) {
            $grants = $this->permissions->grants($player->uuid);
            $context->sender()->sendMessage("Permissions for {$player->name}: " . ($grants === [] ? 'none' : implode(', ', $grants)));

            return CommandResult::SUCCESS;
        }
        if (($operation !== 'grant' && $operation !== 'revoke') || count($arguments) !== 3) {
            return CommandResult::USAGE;
        }
        $changed = $operation === 'grant'
            ? $this->permissions->grant($player->uuid, $player->name, $arguments[2])
            : $this->permissions->revoke($player->uuid, $arguments[2]);
        if ($changed && $this->authorityChanged !== null) {
            ($this->authorityChanged)($player, false);
        }
        $context->sender()->sendMessage($changed ? 'Permission assignment updated.' : 'Permission assignment was already unchanged.');

        return CommandResult::SUCCESS;
    }
}
