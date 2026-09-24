<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Player\Player;
use Bedriox\Api\TextFormat;

final readonly class ListCommand implements BuiltinCommand
{
    public function __construct(private OnlinePlayerResolver $players) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('list', 'Lists connected players.');
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }

    public function execute(CommandContext $context): CommandResult
    {
        $names = array_map(static fn(Player $player): string => $player->name, $this->players->all());
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $count = count($names);
        $context->sender()->sendMessage(CommandMessageStyle::line(
            $context->sender(),
            TextFormat::GREEN,
            $count === 1 ? 'There is 1 player online.' : "There are {$count} players online.",
        ));
        $context->sender()->sendMessage(CommandMessageStyle::line(
            $context->sender(),
            TextFormat::AQUA,
            'Players: ' . ($names === [] ? 'none' : implode(', ', $names)),
        ));

        return CommandResult::success();
    }
}
