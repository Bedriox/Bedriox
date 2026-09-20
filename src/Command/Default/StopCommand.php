<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Closure;

final readonly class StopCommand implements BuiltinCommand
{
    /** @param Closure(): void $stop */
    public function __construct(private Closure $stop) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('stop', 'Stops the server cleanly.', 'stop', permission: 'bedriox.command.stop');
    }

    public function execute(CommandContext $context): CommandResult
    {
        if ($context->arguments() !== []) {
            return CommandResult::USAGE;
        }
        $context->sender()->sendMessage('Stopping the server...');
        ($this->stop)();

        return CommandResult::SUCCESS;
    }
}
