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

namespace Bedriox\Server\Gameplay\Enchanting;

use InvalidArgumentException;

final class EnchantmentRegistry
{
    private const int MAXIMUM_DEFINITIONS = 1_024;

    /** @var array<string, EnchantmentDefinition> */
    private array $byIdentifier = [];

    /** @var array<int, EnchantmentDefinition> */
    private array $byBedrockId = [];

    private int $revision = 0;

    /** @param list<EnchantmentDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
        $this->revision = 0;
    }

    public function register(EnchantmentDefinition $definition, bool $replace = false): void
    {
        $existingIdentifier = $this->byIdentifier[$definition->identifier] ?? null;
        $existingId = $this->byBedrockId[$definition->bedrockId] ?? null;
        if (!$replace && ($existingIdentifier !== null || $existingId !== null)) {
            throw new InvalidArgumentException('Enchantment identifier or Bedrock ID is already registered.');
        }
        if ($existingIdentifier === null && count($this->byIdentifier) >= self::MAXIMUM_DEFINITIONS) {
            throw new InvalidArgumentException('Enchantment registry capacity is exhausted.');
        }
        if ($replace && $existingIdentifier !== null) {
            unset($this->byBedrockId[$existingIdentifier->bedrockId]);
        }
        if ($replace && $existingId !== null && $existingId->identifier !== $definition->identifier) {
            unset($this->byIdentifier[$existingId->identifier]);
        }
        $this->byIdentifier[$definition->identifier] = $definition;
        $this->byBedrockId[$definition->bedrockId] = $definition;
        ++$this->revision;
    }

    public function get(string $identifier): EnchantmentDefinition
    {
        return $this->byIdentifier[$identifier]
            ?? throw new InvalidArgumentException('Enchantment is not registered.');
    }

    public function getByBedrockId(int $id): EnchantmentDefinition
    {
        return $this->byBedrockId[$id]
            ?? throw new InvalidArgumentException('Bedrock enchantment ID is not registered.');
    }

    public function find(string $identifier): ?EnchantmentDefinition
    {
        return $this->byIdentifier[$identifier] ?? null;
    }

    public function findByBedrockId(int $id): ?EnchantmentDefinition
    {
        return $this->byBedrockId[$id] ?? null;
    }

    /** @return list<EnchantmentDefinition> */
    public function all(): array
    {
        return array_values($this->byIdentifier);
    }

    public function revision(): int
    {
        return $this->revision;
    }
}
