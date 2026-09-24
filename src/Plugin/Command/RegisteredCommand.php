<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\Command;
use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandDefinition;

final readonly class RegisteredCommand
{
    public function __construct(
        public int $id,
        public int $sequence,
        public string $owner,
        public bool $pluginOwned,
        public Command $command,
        public CommandDefinition $definition,
        public CommandArguments $arguments,
    ) {}
}
