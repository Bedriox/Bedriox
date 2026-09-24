<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

interface Command
{
    public function definition(): CommandDefinition;

    public function defineArguments(): CommandArguments;

    public function execute(CommandContext $context): CommandResult;
}
