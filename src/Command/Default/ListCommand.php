<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Player\Player;

final readonly class ListCommand implements BuiltinCommand
{
    public function __construct(private OnlinePlayerResolver $players) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('list', 'Lists connected players.', 'list');
    }

    public function execute(CommandContext $context): CommandResult
    {
        if ($context->arguments() !== []) {
            return CommandResult::USAGE;
        }
        $names = array_map(static fn(Player $player): string => $player->name, $this->players->all());
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $context->sender()->sendMessage(count($names) . ' online: ' . ($names === [] ? 'none' : implode(', ', $names)));

        return CommandResult::SUCCESS;
    }
}
