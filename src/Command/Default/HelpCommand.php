<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Server\Plugin\Command\CommandRegistry;

final readonly class HelpCommand implements BuiltinCommand
{
    public function __construct(private CommandRegistry $commands) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('help', 'Lists commands available to you.', aliases: ['commands']);
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }

    public function execute(CommandContext $context): CommandResult
    {
        $definitions = $this->commands->availableTo($context->sender());
        $context->sender()->sendMessage('Available commands (' . count($definitions) . '):');
        foreach ($definitions as $definition) {
            $context->sender()->sendMessage('/' . $definition->name . ' - ' . $definition->description);
        }

        return CommandResult::success();
    }
}
