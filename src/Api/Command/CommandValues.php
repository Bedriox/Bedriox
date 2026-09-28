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

namespace Bedriox\Api\Command;

use BackedEnum;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use InvalidArgumentException;
use LogicException;

final readonly class CommandValues
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        if (count($values) > 32) {
            throw new InvalidArgumentException('A command invocation may contain at most 32 values.');
        }
        foreach (array_keys($values) as $name) {
            if (preg_match('/^[a-z][a-zA-Z0-9_]{0,31}$/D', $name) !== 1) {
                throw new InvalidArgumentException('Command value names must be bounded identifiers beginning with a lowercase letter.');
            }
        }
        $this->values = $values;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        return $this->required($name);
    }

    public function string(string $name): string
    {
        $value = $this->required($name);
        if (!is_string($value)) {
            throw $this->wrongType($name, 'string');
        }

        return $value;
    }

    public function integer(string $name): int
    {
        $value = $this->required($name);
        if (!is_int($value)) {
            throw $this->wrongType($name, 'integer');
        }

        return $value;
    }

    public function float(string $name): float
    {
        $value = $this->required($name);
        if (!is_float($value) && !is_int($value)) {
            throw $this->wrongType($name, 'float');
        }

        return (float) $value;
    }

    public function boolean(string $name): bool
    {
        $value = $this->required($name);
        if (!is_bool($value)) {
            throw $this->wrongType($name, 'boolean');
        }

        return $value;
    }

    public function player(string $name): Player
    {
        $value = $this->required($name);
        if (!$value instanceof Player) {
            throw $this->wrongType($name, Player::class);
        }

        return $value;
    }

    /** @return list<Player> */
    public function players(string $name): array
    {
        $value = $this->required($name);
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->wrongType($name, 'list<Player>');
        }
        foreach ($value as $player) {
            if (!$player instanceof Player) {
                throw $this->wrongType($name, 'list<Player>');
            }
        }

        return $value;
    }

    public function entity(string $name): Player|Entity
    {
        $value = $this->required($name);
        if (!$value instanceof Player && !$value instanceof Entity) {
            throw $this->wrongType($name, Player::class . '|' . Entity::class);
        }

        return $value;
    }

    /** @return list<Player|Entity> */
    public function entities(string $name): array
    {
        $value = $this->required($name);
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->wrongType($name, 'list<' . Player::class . '|' . Entity::class . '>');
        }
        foreach ($value as $entity) {
            if (!$entity instanceof Player && !$entity instanceof Entity) {
                throw $this->wrongType($name, 'list<' . Player::class . '|' . Entity::class . '>');
            }
        }

        return $value;
    }

    /**
     * @template T of BackedEnum
     * @param class-string<T> $enumClass
     * @return T
     */
    public function enum(string $name, string $enumClass): BackedEnum
    {
        $value = $this->required($name);
        if (!$value instanceof $enumClass) {
            throw $this->wrongType($name, $enumClass);
        }

        return $value;
    }

    public function position(string $name): Position
    {
        $value = $this->required($name);
        if (!$value instanceof Position) {
            throw $this->wrongType($name, Position::class);
        }

        return $value;
    }

    public function blockPosition(string $name): BlockPosition
    {
        $value = $this->required($name);
        if (!$value instanceof BlockPosition) {
            throw $this->wrongType($name, BlockPosition::class);
        }

        return $value;
    }

    public function choice(string $name): string
    {
        return $this->string($name);
    }

    public function message(string $name): string
    {
        return $this->string($name);
    }

    public function rawText(string $name): string
    {
        return $this->string($name);
    }

    public function json(string $name): mixed
    {
        return $this->required($name);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    private function required(string $name): mixed
    {
        if (!array_key_exists($name, $this->values)) {
            throw new LogicException("Command value '{$name}' is not present in this invocation.");
        }

        return $this->values[$name];
    }

    private function wrongType(string $name, string $expected): LogicException
    {
        return new LogicException("Command value '{$name}' is not a {$expected}.");
    }
}
