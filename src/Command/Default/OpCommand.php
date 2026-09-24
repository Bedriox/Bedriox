<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Server\Permission\PermissionStore;
use Closure;

final readonly class OpCommand extends OperatorCommand
{
    public function __construct(PermissionStore $permissions, ?Closure $authorityChanged = null)
    {
        parent::__construct($permissions, true, $authorityChanged);
    }

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('op', 'Grants operator authority to an online player.', permission: 'bedriox.command.op');
    }
}
