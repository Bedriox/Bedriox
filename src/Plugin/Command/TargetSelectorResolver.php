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

use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Closure;

/** Resolves a deliberately bounded subset of Bedrock target-selector syntax. */
final readonly class TargetSelectorResolver
{
    private const int MAXIMUM_SELECTOR_BYTES = 256;
    private const int MAXIMUM_FILTERS = 8;
    private const int MAXIMUM_RESULTS = 128;
    private const float MAXIMUM_DISTANCE = 30_000_000.0;

    /**
     * @param Closure(): list<Player> $onlinePlayers
     * @param Closure(): list<Entity> $entities
     * @param Closure(int): int $randomIndex Receives an exclusive upper bound.
     * @param Closure(): ?Position $consoleOrigin
     */
    public function __construct(
        private Closure $onlinePlayers,
        private Closure $entities,
        private Closure $randomIndex,
        private Closure $consoleOrigin,
    ) {}

    /** @return list<Player> */
    public function players(CommandSender $sender, string $input, bool $single): array
    {
        $resolved = $this->resolve($sender, $input, true, $single);
        foreach ($resolved as $target) {
            if (!$target instanceof Player) {
                throw new \LogicException('A player selector resolved a non-player target.');
            }
        }

        return $resolved;
    }

    /** @return list<Player|Entity> */
    public function entities(CommandSender $sender, string $input, bool $single): array
    {
        return $this->resolve($sender, $input, false, $single);
    }

    /** @return list<Player|Entity> */
    private function resolve(CommandSender $sender, string $input, bool $playersOnly, bool $single): array
    {
        if ($input === '' || strlen($input) > self::MAXIMUM_SELECTOR_BYTES
            || preg_match('//u', $input) !== 1 || str_contains($input, "\0")) {
            throw new CommandBindingException('The target selector is malformed or exceeds its size limit.');
        }
        if (!str_starts_with($input, '@')) {
            $target = $this->literal($input, $playersOnly);

            return [$target];
        }

        if (preg_match('/^@([aAeEnNpPrRsS])(?:\[(.*)\])?$/Ds', $input, $matches) !== 1) {
            throw new CommandBindingException("Unsupported target selector '{$input}'.");
        }
        $kind = strtolower($matches[1]);
        if ($playersOnly && ($kind === 'e' || $kind === 'n')) {
            throw new CommandBindingException("Selector '@{$kind}' is not valid for a player-only argument.");
        }
        $options = $this->parseOptions($matches[2] ?? '');
        [$limit, $sort] = $this->selectionDefaults($kind, $options);
        if ($single) {
            $limit = min($limit, 2);
        }

        $players = $this->connectedPlayers();
        $targets = match ($kind) {
            'a', 'p', 'r' => $players,
            'e', 'n' => [...$players, ...$this->generalEntities()],
            's' => $this->self($sender),
        };
        $origin = $sender instanceof PlayerCommandSender ? $sender->player()->position : ($this->consoleOrigin)();
        if ($origin !== null && (!is_finite($origin->x) || !is_finite($origin->y) || !is_finite($origin->z)
            || abs($origin->x) > self::MAXIMUM_DISTANCE || abs($origin->z) > self::MAXIMUM_DISTANCE
            || abs($origin->y) > 2_048.0)) {
            throw new \LogicException('The command selector origin is invalid.');
        }
        $targets = $this->filter($targets, $options, $origin);
        $targets = $this->sort($targets, $sort, $origin);
        $targets = array_slice($targets, 0, $limit);
        if ($targets === []) {
            throw new CommandBindingException('No entities matched the target selector.');
        }
        if ($single && count($targets) !== 1) {
            throw new CommandBindingException('The target selector matched more than one entity.');
        }

        return $targets;
    }

    private function literal(string $input, bool $playersOnly): Player|Entity
    {
        $matches = array_values(array_filter(
            $this->connectedPlayers(),
            static fn(Player $player): bool => strcasecmp($player->name, $input) === 0,
        ));
        if (!$playersOnly) {
            foreach ($this->generalEntities() as $entity) {
                if (strcasecmp($entity->getUniqueId(), $input) === 0) {
                    $matches[] = $entity;
                }
            }
        }
        if (count($matches) !== 1) {
            throw new CommandBindingException(count($matches) === 0
                ? "Target '{$input}' is not available."
                : "Target '{$input}' is ambiguous.");
        }

        return $matches[0];
    }

    /** @return list<Player|Entity> */
    private function self(CommandSender $sender): array
    {
        if (!$sender instanceof PlayerCommandSender || !$sender->player()->isConnected()) {
            throw new CommandBindingException('The @s selector requires a connected player sender.');
        }

        return [$sender->player()];
    }

    /** @return array<string, string> */
    private function parseOptions(string $input): array
    {
        if ($input === '') {
            return [];
        }
        $parts = explode(',', $input);
        if (count($parts) > self::MAXIMUM_FILTERS) {
            throw new CommandBindingException('The target selector contains too many filters.');
        }
        $options = [];
        foreach ($parts as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2 || preg_match('/^(?:name|type|distance|limit|sort)$/D', $pair[0]) !== 1
                || $pair[1] === '' || isset($options[$pair[0]])) {
                throw new CommandBindingException('The target selector contains an invalid or duplicate filter.');
            }
            $options[$pair[0]] = $pair[1];
        }

        return $options;
    }

    /** @param array<string, string> $options
     *  @return array{int, string}
     */
    private function selectionDefaults(string $kind, array $options): array
    {
        $limit = match ($kind) {
            'p', 'r', 's', 'n' => 1,
            default => self::MAXIMUM_RESULTS,
        };
        if (isset($options['limit'])) {
            if (preg_match('/^[1-9][0-9]{0,2}$/D', $options['limit']) !== 1) {
                throw new CommandBindingException('Selector limit must be a positive whole number.');
            }
            $limit = (int) $options['limit'];
            if ($limit > self::MAXIMUM_RESULTS) {
                throw new CommandBindingException('Selector limit exceeds 128 results.');
            }
        }
        $sort = $options['sort'] ?? match ($kind) {
            'p', 'n' => 'nearest',
            'r' => 'random',
            default => 'arbitrary',
        };
        if (!in_array($sort, ['arbitrary', 'nearest', 'furthest', 'random'], true)) {
            throw new CommandBindingException('Selector sort must be arbitrary, nearest, furthest, or random.');
        }

        return [$limit, $sort];
    }

    /**
     * @param list<Player|Entity> $targets
     * @param array<string, string> $options
     * @return list<Player|Entity>
     */
    private function filter(array $targets, array $options, ?Position $origin): array
    {
        $type = isset($options['type']) ? self::canonicalType($options['type']) : null;
        [$minimumDistance, $maximumDistance] = isset($options['distance'])
            ? self::distanceRange($options['distance'])
            : [null, null];
        if (($minimumDistance !== null || $maximumDistance !== null) && $origin === null) {
            throw new CommandBindingException('Distance filters require a connected player sender.');
        }
        $name = $options['name'] ?? null;

        return array_values(array_filter(
            $targets,
            static function (Player|Entity $target) use ($type, $name, $origin, $minimumDistance, $maximumDistance): bool {
                if ($name !== null && (!$target instanceof Player || strcasecmp($target->name, $name) !== 0)) {
                    return false;
                }
                if ($type !== null) {
                    $targetType = $target instanceof Player ? 'minecraft:player' : $target->getType()->identifier();
                    if ($targetType !== $type) {
                        return false;
                    }
                }
                if ($origin !== null && ($minimumDistance !== null || $maximumDistance !== null)) {
                    $distance = sqrt(self::distanceSquared($origin, self::position($target)));
                    if (($minimumDistance !== null && $distance < $minimumDistance)
                        || ($maximumDistance !== null && $distance > $maximumDistance)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * @param list<Player|Entity> $targets
     * @return list<Player|Entity>
     */
    private function sort(array $targets, string $sort, ?Position $origin): array
    {
        if (($sort === 'nearest' || $sort === 'furthest') && $origin === null) {
            throw new CommandBindingException("Selector sorting mode '{$sort}' requires a connected player sender.");
        }
        usort($targets, static fn(Player|Entity $left, Player|Entity $right): int =>
            strcmp(self::stableKey($left), self::stableKey($right)));
        if ($sort === 'nearest' || $sort === 'furthest') {
            usort($targets, static function (Player|Entity $left, Player|Entity $right) use ($origin, $sort): int {
                $comparison = self::distanceSquared($origin, self::position($left))
                    <=> self::distanceSquared($origin, self::position($right));
                if ($sort === 'furthest') {
                    $comparison *= -1;
                }

                return $comparison ?: strcmp(self::stableKey($left), self::stableKey($right));
            });
        } elseif ($sort === 'random') {
            for ($index = count($targets) - 1; $index > 0; --$index) {
                $selected = ($this->randomIndex)($index + 1);
                if ($selected < 0 || $selected > $index) {
                    throw new \LogicException('The selector random source returned an out-of-range index.');
                }
                [$targets[$index], $targets[$selected]] = [$targets[$selected], $targets[$index]];
            }
        }

        return array_values($targets);
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

    /** @return list<Entity> */
    private function generalEntities(): array
    {
        $entities = ($this->entities)();
        usort($entities, static fn(Entity $left, Entity $right): int => $left->getRuntimeId() <=> $right->getRuntimeId());

        return $entities;
    }

    private static function canonicalType(string $type): string
    {
        $type = strtolower(str_contains($type, ':') ? $type : 'minecraft:' . $type);
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $type) !== 1 || strlen($type) > 128) {
            throw new CommandBindingException('Selector type is not a valid entity identifier.');
        }

        return $type;
    }

    /** @return array{?float, ?float} */
    private static function distanceRange(string $range): array
    {
        $parts = explode('..', $range, 2);
        if (count($parts) === 1) {
            $value = self::distance($parts[0]);

            return [$value, $value];
        }
        if ($parts[0] === '' && $parts[1] === '') {
            throw new CommandBindingException('Selector distance range cannot be empty.');
        }
        $minimum = $parts[0] === '' ? null : self::distance($parts[0]);
        $maximum = $parts[1] === '' ? null : self::distance($parts[1]);
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new CommandBindingException('Selector distance minimum cannot exceed its maximum.');
        }

        return [$minimum, $maximum];
    }

    private static function distance(string $value): float
    {
        if (!is_numeric($value)) {
            throw new CommandBindingException('Selector distance must be numeric.');
        }
        $distance = (float) $value;
        if (!is_finite($distance) || $distance < 0.0 || $distance > self::MAXIMUM_DISTANCE) {
            throw new CommandBindingException('Selector distance is outside its supported range.');
        }

        return $distance;
    }

    private static function position(Player|Entity $target): Position
    {
        return $target instanceof Player ? $target->position : $target->getPosition();
    }

    private static function distanceSquared(Position $origin, Position $target): float
    {
        return ($target->x - $origin->x) ** 2
            + ($target->y - $origin->y) ** 2
            + ($target->z - $origin->z) ** 2;
    }

    private static function stableKey(Player|Entity $target): string
    {
        return $target instanceof Player
            ? '0:' . strtolower($target->uuid)
            : '1:' . str_pad((string) $target->getRuntimeId(), 20, '0', STR_PAD_LEFT);
    }
}
