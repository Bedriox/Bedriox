<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Server\Plugin\Command\CommandRegistry;

final readonly class HelpCommand implements BuiltinCommand
{
    public function __construct(private CommandRegistry $commands) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('help', 'Lists commands available to you.', 'help', aliases: ['commands']);
    }

    public function execute(CommandContext $context): CommandResult
    {
        if ($context->arguments() !== []) {
            return CommandResult::USAGE;
        }
        $definitions = $this->commands->availableTo($context->sender());
        $context->sender()->sendMessage('Available commands (' . count($definitions) . '):');
        foreach ($definitions as $definition) {
            $context->sender()->sendMessage('/' . $definition->name . ' - ' . $definition->description);
        }

        return CommandResult::SUCCESS;
    }
}
