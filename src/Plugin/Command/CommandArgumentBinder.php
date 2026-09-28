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

namespace Bedriox\Server\Plugin\Command;

use BackedEnum;
use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandParameterType;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use Closure;
use JsonException;

/** Binds bounded command tokens to the public typed command value model. */
final readonly class CommandArgumentBinder
{
    private TargetSelectorResolver $selectors;

    /**
     * @param Closure(): list<Player> $onlinePlayers
     * @param null|Closure(): list<Entity> $entities
     * @param null|Closure(int): int $randomIndex
     * @param null|Closure(): ?Position $selectorOrigin
     */
    public function __construct(
        private Closure $onlinePlayers,
        ?Closure $entities = null,
        ?Closure $randomIndex = null,
        ?Closure $selectorOrigin = null,
    ) {
        $this->selectors = new TargetSelectorResolver(
            $onlinePlayers,
            $entities ?? static fn(): array => [],
            $randomIndex ?? static fn(int $upperBound): int => random_int(0, $upperBound - 1),
            $selectorOrigin ?? static fn(): ?Position => null,
        );
    }

    /** @param list<string> $tokens */
    public function bind(CommandArguments $arguments, CommandSender $sender, array $tokens): CommandValues
    {
        $matches = [];
        $failures = [];
        foreach ($arguments->overloads() as $overload) {
            try {
                $matches[] = $this->bindOverload($overload, $sender, $tokens);
            } catch (CommandBindingException $failure) {
                $failures[] = $failure->getMessage();
            }
        }
        if ($matches === []) {
            throw new CommandBindingException($failures[0] ?? 'The command arguments do not match any supported form.');
        }
        usort($matches, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        if (isset($matches[1]) && $matches[0]['score'] === $matches[1]['score']) {
            throw new CommandBindingException('The command arguments match more than one command form.');
        }

        return new CommandValues($matches[0]['values']);
    }

    /**
     * @param list<string> $tokens
     * @return array{values: array<string, mixed>, score: int}
     */
    private function bindOverload(CommandOverload $overload, CommandSender $sender, array $tokens): array
    {
        $values = [];
        $offset = 0;
        $score = 0;
        foreach ($overload->parameters() as $parameter) {
            if ($offset >= count($tokens)) {
                if (!$parameter->isOptional()) {
                    throw new CommandBindingException("Missing required argument '{$parameter->name()}'.");
                }
                if ($parameter->hasDefault()) {
                    $values[$parameter->name()] = $parameter->defaultValue();
                }
                continue;
            }
            [$value, $consumed, $specificity] = $this->bindParameter($parameter, $sender, $tokens, $offset);
            $values[$parameter->name()] = $value;
            $offset += $consumed;
            $score += $specificity;
        }
        if ($offset !== count($tokens)) {
            throw new CommandBindingException('Too many command arguments were provided.');
        }

        return ['values' => $values, 'score' => $score];
    }

    /**
     * @param list<string> $tokens
     * @return array{mixed, int, int}
     */
    private function bindParameter(CommandParameter $parameter, CommandSender $sender, array $tokens, int $offset): array
    {
        $token = $tokens[$offset];

        return match ($parameter->type()) {
            CommandParameterType::STRING => [$token, 1, 10],
            CommandParameterType::INTEGER => [$this->integer($parameter, $token), 1, 40],
            CommandParameterType::FLOAT => [$this->float($parameter, $token), 1, 30],
            CommandParameterType::BOOLEAN => [$this->boolean($parameter, $token), 1, 70],
            CommandParameterType::ONLINE_PLAYER => [$this->onlinePlayer($token), 1, 60],
            CommandParameterType::PLAYERS => [$this->players($sender, $token), 1, 50],
            CommandParameterType::ENTITY => [$this->entity($sender, $token), 1, 55],
            CommandParameterType::ENTITIES => [$this->entities($sender, $token), 1, 50],
            CommandParameterType::CHOICE => [$this->choice($parameter, $token), 1, 80],
            CommandParameterType::ENUM => [$this->enum($parameter, $token), 1, 80],
            CommandParameterType::SOFT_ENUM => [$this->softEnum($parameter, $token), 1, 75],
            CommandParameterType::POSITION => [$this->position($sender, $tokens, $offset), 3, 50],
            CommandParameterType::BLOCK_POSITION => [$this->blockPosition($sender, $tokens, $offset), 3, 50],
            CommandParameterType::MESSAGE, CommandParameterType::RAW_TEXT => [implode(' ', array_slice($tokens, $offset)), count($tokens) - $offset, 5],
            CommandParameterType::JSON => [
                $this->json(implode(' ', array_slice($tokens, $offset))),
                count($tokens) - $offset,
                40,
            ],
            CommandParameterType::LITERAL => [$this->literal($parameter, $token), 1, 100],
        };
    }

    private function integer(CommandParameter $parameter, string $token): int
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $token) !== 1) {
            throw new CommandBindingException("Argument '{$parameter->name()}' must be a whole number.");
        }
        $value = filter_var($token, FILTER_VALIDATE_INT);
        if (!is_int($value)) {
            throw new CommandBindingException("Argument '{$parameter->name()}' is outside the supported integer range.");
        }
        $this->requireRange($parameter, $value);

        return $value;
    }

    private function float(CommandParameter $parameter, string $token): float
    {
        if (!is_numeric($token)) {
            throw new CommandBindingException("Argument '{$parameter->name()}' must be a number.");
        }
        $value = (float) $token;
        if (!is_finite($value)) {
            throw new CommandBindingException("Argument '{$parameter->name()}' must be finite.");
        }
        $this->requireRange($parameter, $value);

        return $value;
    }

    private function requireRange(CommandParameter $parameter, int|float $value): void
    {
        if (($parameter->minimumValue() !== null && $value < $parameter->minimumValue())
            || ($parameter->maximumValue() !== null && $value > $parameter->maximumValue())) {
            throw new CommandBindingException("Argument '{$parameter->name()}' is outside its allowed range.");
        }
    }

    private function boolean(CommandParameter $parameter, string $token): bool
    {
        return match (strtolower($token)) {
            'true' => true,
            'false' => false,
            default => throw new CommandBindingException("Argument '{$parameter->name()}' must be true or false."),
        };
    }

    private function onlinePlayer(string $token): Player
    {
        $matches = array_values(array_filter(
            $this->connectedPlayers(),
            static fn(Player $player): bool => strcasecmp($player->name, $token) === 0,
        ));
        if (count($matches) !== 1) {
            throw new CommandBindingException(count($matches) === 0
                ? "Player '{$token}' is not connected."
                : "Player name '{$token}' is ambiguous.");
        }

        return $matches[0];
    }

    /** @return list<Player> */
    private function players(CommandSender $sender, string $token): array
    {
        return $this->selectors->players($sender, $token, false);
    }

    private function entity(CommandSender $sender, string $token): Player|Entity
    {
        return $this->selectors->entities($sender, $token, true)[0];
    }

    /** @return list<Player|Entity> */
    private function entities(CommandSender $sender, string $token): array
    {
        return $this->selectors->entities($sender, $token, false);
    }

    /** @return list<Player> */
    private function connectedPlayers(): array
    {
        $players = array_values(array_filter(
            ($this->onlinePlayers)(),
            static fn(Player $player): bool => $player->isConnected(),
        ));
        usort($players, static fn(Player $left, Player $right): int => strcasecmp($left->name, $right->name));

        return $players;
    }

    private function choice(CommandParameter $parameter, string $token): string
    {
        foreach ($parameter->choices() as $choice) {
            if (strcasecmp($choice, $token) === 0) {
                return $choice;
            }
        }

        throw new CommandBindingException("Argument '{$parameter->name()}' must be one of: " . implode(', ', $parameter->choices()) . '.');
    }

    private function enum(CommandParameter $parameter, string $token): BackedEnum
    {
        $class = $parameter->enumClass();
        if ($class === null) {
            throw new CommandBindingException("Argument '{$parameter->name()}' has no enum type.");
        }
        foreach ($class::cases() as $case) {
            if (strcasecmp((string) $case->value, $token) === 0) {
                return $case;
            }
        }

        throw new CommandBindingException("Argument '{$parameter->name()}' must be one of: " . implode(', ', $parameter->choices()) . '.');
    }

    private function softEnum(CommandParameter $parameter, string $token): string
    {
        $softEnum = $parameter->softEnumValue();
        if ($softEnum === null || !$softEnum->isRegistered()) {
            throw new CommandBindingException("Argument '{$parameter->name()}' is unavailable.");
        }
        foreach ($softEnum->values() as $value) {
            if (strcasecmp($value, $token) === 0) {
                return $value;
            }
        }

        throw new CommandBindingException("Argument '{$parameter->name()}' is not currently available.");
    }

    /** @param list<string> $tokens */
    private function position(CommandSender $sender, array $tokens, int $offset): Position
    {
        if (!isset($tokens[$offset + 1], $tokens[$offset + 2])) {
            throw new CommandBindingException('A position requires x, y, and z coordinates.');
        }
        $base = $sender instanceof PlayerCommandSender ? $sender->player()->position : null;

        return new Position(
            $this->coordinate($tokens[$offset], $base?->x),
            $this->coordinate($tokens[$offset + 1], $base?->y),
            $this->coordinate($tokens[$offset + 2], $base?->z),
        );
    }

    /** @param list<string> $tokens */
    private function blockPosition(CommandSender $sender, array $tokens, int $offset): BlockPosition
    {
        $position = $this->position($sender, $tokens, $offset);

        return new BlockPosition((int) floor($position->x), (int) floor($position->y), (int) floor($position->z));
    }

    private function coordinate(string $token, ?float $base): float
    {
        if (str_starts_with($token, '^')) {
            throw new CommandBindingException('Local ^ coordinates are not supported by this command parameter.');
        }
        if (str_starts_with($token, '~')) {
            if ($base === null) {
                throw new CommandBindingException('Relative coordinates require a player sender.');
            }
            $suffix = substr($token, 1);
            if ($suffix === '') {
                return $base;
            }
            if (!is_numeric($suffix) || !is_finite((float) $suffix)) {
                throw new CommandBindingException("Invalid relative coordinate '{$token}'.");
            }

            return $base + (float) $suffix;
        }
        if (!is_numeric($token) || !is_finite((float) $token)) {
            throw new CommandBindingException("Invalid coordinate '{$token}'.");
        }

        return (float) $token;
    }

    private function json(string $token): mixed
    {
        try {
            return json_decode($token, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CommandBindingException('The JSON argument is invalid or exceeds the nesting limit.');
        }
    }

    private function literal(CommandParameter $parameter, string $token): string
    {
        $literal = $parameter->literalValue();
        if ($literal === null || strcasecmp($literal, $token) !== 0) {
            throw new CommandBindingException("Expected command literal '{$literal}'.");
        }

        return $literal;
    }
}
