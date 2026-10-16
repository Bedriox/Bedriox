<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot;
use Bedriox\Api\Scoreboard\ScoreboardManager;
use Bedriox\Api\Scoreboard\ScoreboardObjective;
use Bedriox\Api\Scoreboard\ScoreboardSortOrder;
use Bedriox\Server\Plugin\PluginException;
use InvalidArgumentException;
use LogicException;

/** Operator command backed by the same scoreboard manager exposed to plugins. */
final readonly class ScoreboardCommand implements BuiltinCommand
{
    public function __construct(
        private ScoreboardManager $scoreboards,
        private OnlinePlayerResolver $players,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'scoreboard',
            'Manages scoreboard objectives and player scores.',
            permission: 'bedriox.command.scoreboard',
        );
    }

    public function defineArguments(): CommandArguments
    {
        $slot = static fn(): CommandParameter => CommandParameter::choice('slot', array_column(
            ScoreboardDisplaySlot::cases(),
            'value',
        ));
        $objective = static fn(): CommandParameter => CommandParameter::string('objective');
        $player = static fn(): CommandParameter => CommandParameter::onlinePlayer('player');

        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('objectives'))
                ->addArgument(CommandParameter::literal('list')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('objectives'))
                ->addArgument(CommandParameter::literal('add'))
                ->addArgument($objective())
                ->addArgument(CommandParameter::rawText('displayName')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('objectives'))
                ->addArgument(CommandParameter::literal('remove'))
                ->addArgument($objective()))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('objectives'))
                ->addArgument(CommandParameter::literal('setdisplay'))
                ->addArgument($slot()))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('objectives'))
                ->addArgument(CommandParameter::literal('setdisplay'))
                ->addArgument($slot())
                ->addArgument($objective()))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('players'))
                ->addArgument(CommandParameter::literal('get'))
                ->addArgument($player())
                ->addArgument($objective()))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('players'))
                ->addArgument(CommandParameter::choice('operation', ['set', 'add', 'remove']))
                ->addArgument($player())
                ->addArgument($objective())
                ->addArgument(CommandParameter::integer('score')
                    ->minimum(ScoreboardObjective::MINIMUM_SCORE)
                    ->maximum(ScoreboardObjective::MAXIMUM_SCORE)))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('players'))
                ->addArgument(CommandParameter::literal('reset'))
                ->addArgument($player()))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('players'))
                ->addArgument(CommandParameter::literal('reset'))
                ->addArgument($player())
                ->addArgument($objective()));
    }

    public function execute(CommandContext $context): CommandResult
    {
        try {
            return $context->values()->has('objectives')
                ? $this->objectives($context)
                : $this->players($context);
        } catch (InvalidArgumentException|LogicException|PluginException $exception) {
            return CommandResult::failure($exception->getMessage());
        }
    }

    private function objectives(CommandContext $context): CommandResult
    {
        $values = $context->values();
        if ($values->has('list')) {
            $objectives = $this->scoreboards->getObjectives();
            if ($objectives === []) {
                return CommandResult::information('There are no scoreboard objectives.');
            }
            $names = array_map(
                static fn(ScoreboardObjective $objective): string => $objective->getId()
                    . ' (' . $objective->getDisplayName() . ')',
                $objectives,
            );

            return CommandResult::information(self::boundedList(
                count($objectives) . ' scoreboard objective(s): ',
                $names,
            ));
        }

        $id = $values->has('objective') ? strtolower($values->string('objective')) : null;
        if ($values->has('add')) {
            if ($id === null) {
                return CommandResult::failure('An objective ID is required.');
            }
            $this->scoreboards->createObjective($id, $values->rawText('displayName'));

            return CommandResult::administrativeSuccess("Created scoreboard objective '{$id}'.");
        }
        if ($values->has('remove')) {
            if ($id === null || !$this->scoreboards->removeObjective($id)) {
                return CommandResult::failure('That scoreboard objective does not exist.');
            }

            return CommandResult::administrativeSuccess("Removed scoreboard objective '{$id}'.");
        }

        $slot = ScoreboardDisplaySlot::from($values->choice('slot'));
        if ($id === null) {
            foreach ($this->scoreboards->getObjectives() as $objective) {
                foreach ($this->players->all() as $player) {
                    $objective->hideFrom($player, $slot);
                }
            }

            return CommandResult::administrativeSuccess("Cleared the {$slot->value} scoreboard display.");
        }
        $objective = $this->objective($id);
        foreach ($this->players->all() as $player) {
            $objective->showTo($player, $slot, ScoreboardSortOrder::DESCENDING);
        }

        return CommandResult::administrativeSuccess(
            "Displayed scoreboard objective '{$id}' in the {$slot->value} slot.",
        );
    }

    private function players(CommandContext $context): CommandResult
    {
        $values = $context->values();
        $player = $values->player('player');
        if ($values->has('reset') && !$values->has('objective')) {
            $reset = 0;
            foreach ($this->scoreboards->getObjectives() as $objective) {
                $reset += (int) $objective->resetScore($player);
            }

            return CommandResult::administrativeSuccess(
                "Reset {$reset} scoreboard score(s) for {$player->name}.",
            );
        }

        $objective = $this->objective(strtolower($values->string('objective')));
        if ($values->has('get')) {
            $score = $objective->getScore($player);

            return $score === null
                ? CommandResult::failure("{$player->name} has no score in '{$objective->getId()}'.")
                : CommandResult::information(
                    "{$player->name} has a score of {$score} in '{$objective->getId()}'.",
                );
        }
        if ($values->has('reset')) {
            if (!$objective->resetScore($player)) {
                return CommandResult::warning(
                    "{$player->name} has no score in '{$objective->getId()}'.",
                );
            }

            return CommandResult::administrativeSuccess(
                "Reset {$player->name}'s score in '{$objective->getId()}'.",
            );
        }

        $requested = $values->integer('score');
        $operation = $values->choice('operation');
        if ($operation !== 'set' && $requested < 0) {
            return CommandResult::failure('Score amounts for add and remove must not be negative.');
        }
        $score = match ($operation) {
            'set' => $this->setScore($objective, $player, $requested),
            'add' => $objective->addScore($player, $requested),
            'remove' => $objective->subtractScore($player, $requested),
            default => throw new LogicException('Unsupported scoreboard operation.'),
        };

        return CommandResult::administrativeSuccess(
            "Set {$player->name}'s score in '{$objective->getId()}' to {$score}.",
        );
    }

    private function objective(string $id): ScoreboardObjective
    {
        return $this->scoreboards->getObjective($id)
            ?? throw new InvalidArgumentException("Scoreboard objective '{$id}' does not exist.");
    }

    private function setScore(ScoreboardObjective $objective, Player $player, int $score): int
    {
        $objective->setScore($player, $score);

        return $score;
    }

    /** @param list<string> $entries */
    private static function boundedList(string $prefix, array $entries): string
    {
        $message = $prefix;
        foreach ($entries as $index => $entry) {
            $candidate = $message . ($index === 0 ? '' : ', ') . $entry;
            if (strlen($candidate) > 1_000) {
                return $message . ', ...';
            }
            $message = $candidate;
        }

        return $message;
    }
}
