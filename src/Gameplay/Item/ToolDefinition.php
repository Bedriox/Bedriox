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

namespace Bedriox\Server\Gameplay\Item;

use InvalidArgumentException;

/** Immutable mining and durability properties of one tool item. */
final readonly class ToolDefinition
{
    private function __construct(
        public ToolType $type,
        public ?ToolTier $tier,
        public int $durability,
        public float $miningEfficiency,
        public int $durabilityDamagePerBlock,
        public int $durabilityDamagePerAttack,
    ) {
        if ($durability < 1 || $miningEfficiency <= 0.0 || $durabilityDamagePerBlock < 1 || $durabilityDamagePerAttack < 0) {
            throw new InvalidArgumentException('Tool properties are outside their supported ranges.');
        }
    }

    public static function tiered(ToolType $type, ToolTier $tier): self
    {
        if ($type === ToolType::Shears) {
            throw new InvalidArgumentException('Shears are not a tiered tool.');
        }

        return new self(
            $type,
            $tier,
            $tier->durability(),
            $tier->miningEfficiency(),
            $type === ToolType::Sword ? 2 : 1,
            match ($type) {
                ToolType::Sword, ToolType::Spear, ToolType::Hoe => 1,
                default => 2,
            },
        );
    }

    public static function shears(): self
    {
        return new self(ToolType::Shears, null, 239, 1.0, 1, 0);
    }

    public function harvestLevel(): int
    {
        return $this->tier?->harvestLevel() ?? 0;
    }

    /** Authoritative melee damage for the unenchanted tool. */
    public function attackDamage(): float
    {
        if ($this->tier === null) {
            return 1.0;
        }

        return max(1.0, $this->tier->baseAttackDamage() - match ($this->type) {
            ToolType::Sword => 0.0,
            ToolType::Axe => 1.0,
            ToolType::Pickaxe => 2.0,
            ToolType::Shovel => 3.0,
            ToolType::Hoe => 4.0,
            ToolType::Spear => 3.0,
            ToolType::Shears => $this->tier->baseAttackDamage() - 1.0,
        });
    }
}
