<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\TextFormat;
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
        $build = BuildInfo::current();
        $context->sender()->sendMessage(CommandMessageStyle::line(
            $context->sender(),
            TextFormat::GREEN,
            "This server is running Bedriox version {$build->serverVersion} (protocol {$build->protocolVersion}).",
        ));
        $context->sender()->sendMessage(CommandMessageStyle::line(
            $context->sender(),
            TextFormat::AQUA,
            'Visit https://bedriox.com',
        ));

        return CommandResult::SUCCESS;
    }
}
