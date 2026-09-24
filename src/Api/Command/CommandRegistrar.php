<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

interface CommandRegistrar
{
    public function register(Command $command): CommandSubscription;

    /** @param list<string> $values */
    public function registerSoftEnum(string $name, array $values = []): CommandSoftEnum;

    public function submitJob(CommandJob $job): CommandJobSubscription;
}
