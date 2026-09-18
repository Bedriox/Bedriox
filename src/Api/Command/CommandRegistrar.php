<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

interface CommandRegistrar
{
    /** @param callable(CommandContext): CommandResult $handler */
    public function register(CommandDefinition $definition, callable $handler): CommandSubscription;

    public function submitJob(CommandJob $job): CommandJobSubscription;
}
