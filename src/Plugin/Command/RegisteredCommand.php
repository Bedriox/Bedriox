<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Closure;

final readonly class RegisteredCommand
{
    /** @param Closure(CommandContext): CommandResult $handler */
    public function __construct(
        public int $id,
        public int $sequence,
        public string $plugin,
        public CommandDefinition $definition,
        public Closure $handler,
    ) {}
}
