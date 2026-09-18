<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandJob;
use Bedriox\Api\Command\CommandJobSubscription;
use Bedriox\Api\Command\CommandRegistrar;
use Bedriox\Api\Command\CommandSubscription;

final readonly class OwnedCommandRegistrar implements CommandRegistrar
{
    public function __construct(private string $plugin, private CommandRegistry $registry) {}

    public function register(CommandDefinition $definition, callable $handler): CommandSubscription
    {
        return $this->registry->register($this->plugin, $definition, $handler);
    }

    public function submitJob(CommandJob $job): CommandJobSubscription
    {
        return $this->registry->submitJob($this->plugin, $job);
    }
}
