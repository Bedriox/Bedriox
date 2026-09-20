<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;

/** Internal contract for a server-owned default command. */
interface BuiltinCommand
{
    public function definition(): CommandDefinition;

    public function execute(CommandContext $context): CommandResult;
}
