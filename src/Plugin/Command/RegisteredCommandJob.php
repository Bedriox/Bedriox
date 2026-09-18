<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandJob;

final readonly class RegisteredCommandJob
{
    public function __construct(public int $id, public string $plugin, public CommandJob $job) {}
}
