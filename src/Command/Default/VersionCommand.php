<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Server\BuildInfo;

final readonly class VersionCommand implements BuiltinCommand
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('version', 'Shows Bedriox and protocol version information.', 'version', aliases: ['ver']);
    }

    public function execute(CommandContext $context): CommandResult
    {
        if ($context->arguments() !== []) {
            return CommandResult::USAGE;
        }
        foreach (BuildInfo::current()->publicSummary() as $line) {
            $context->sender()->sendMessage($line);
        }

        return CommandResult::SUCCESS;
    }
}
