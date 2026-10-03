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

namespace Bedriox\Api\Entity\Value;

/** Current Bedrock boat variants and their actor-metadata IDs. */
enum BoatVariant: int
{
    case OAK = 0;
    case SPRUCE = 1;
    case BIRCH = 2;
    case JUNGLE = 3;
    case ACACIA = 4;
    case DARK_OAK = 5;
    case MANGROVE = 6;
    case BAMBOO = 7;
    case CHERRY = 8;
    case PALE_OAK = 9;
    case POPLAR = 10;

    public static function fromItem(string $identifier, int $auxValue = 0): ?self
    {
        if ($identifier === 'minecraft:boat') {
            return $auxValue <= self::PALE_OAK->value ? self::tryFrom($auxValue) : null;
        }
        if ($identifier === 'minecraft:chest_boat') {
            return self::tryFrom($auxValue);
        }
        $base = str_replace(['minecraft:', '_chest_boat', '_chest_raft', '_boat', '_raft'], '', $identifier);

        return match ($base) {
            'oak' => self::OAK,
            'spruce' => self::SPRUCE,
            'birch' => self::BIRCH,
            'jungle' => self::JUNGLE,
            'acacia' => self::ACACIA,
            'dark_oak' => self::DARK_OAK,
            'mangrove' => self::MANGROVE,
            'bamboo' => self::BAMBOO,
            'cherry' => self::CHERRY,
            'pale_oak' => self::PALE_OAK,
            'poplar' => self::POPLAR,
            default => null,
        };
    }

    public function matchesItem(string $identifier, int $auxValue = 0): bool
    {
        if ($identifier === 'minecraft:boat') {
            return $this !== self::POPLAR && $this->value === $auxValue;
        }
        if ($identifier === 'minecraft:chest_boat') {
            return $this->value === $auxValue;
        }

        return $identifier === $this->itemIdentifier()
            || $identifier === $this->itemIdentifier(true);
    }

    public function itemIdentifier(bool $chest = false): string
    {
        $wood = strtolower($this->name);
        if ($this === self::BAMBOO) {
            return 'minecraft:bamboo_' . ($chest ? 'chest_raft' : 'raft');
        }

        return 'minecraft:' . $wood . ($chest ? '_chest_boat' : '_boat');
    }
}
