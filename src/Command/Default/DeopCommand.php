<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Server\Permission\PermissionStore;
use Closure;

final readonly class DeopCommand extends OperatorCommand
{
    public function __construct(PermissionStore $permissions, OnlinePlayerResolver $players, ?Closure $authorityChanged = null)
    {
        parent::__construct($permissions, $players, false, $authorityChanged);
    }

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('deop', 'Removes operator authority from an online player.', 'deop <player>', permission: 'bedriox.command.op');
    }
}
