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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;

/** Applies one compost interaction using an explicit deterministic roll from the simulation RNG. */
final readonly class ComposterProcessor
{
    /** Vanilla compost success percentages for canonical item identifiers. */
    private const array CHANCES = [
        'minecraft:wheat_seeds' => 30,
        'minecraft:beetroot_seeds' => 30,
        'minecraft:kelp' => 30,
        'minecraft:dried_kelp' => 30,
        'minecraft:short_grass' => 30,
        'minecraft:cactus' => 50,
        'minecraft:melon_slice' => 50,
        'minecraft:sugar_cane' => 50,
        'minecraft:wheat' => 65,
        'minecraft:carrot' => 65,
        'minecraft:potato' => 65,
        'minecraft:apple' => 65,
        'minecraft:bread' => 85,
        'minecraft:baked_potato' => 85,
        'minecraft:hay_block' => 85,
        'minecraft:nether_wart_block' => 85,
        'minecraft:cake' => 100,
        'minecraft:pumpkin_pie' => 100,
    ];

    public function insert(ComposterState $state, ContainerItemStack $item, int $roll): ?ComposterResult
    {
        if ($roll < 0 || $roll > 99) {
            throw new InvalidArgumentException('Composter roll must be between zero and 99.');
        }
        $chance = self::chance($item->identifier);
        if ($chance === null || $state->level >= 7) {
            return null;
        }
        $succeeded = $roll < $chance;
        return new ComposterResult(new ComposterState($succeeded ? $state->level + 1 : $state->level), true);
    }

    public function mature(ComposterState $state): ComposterState
    {
        return new ComposterState($state->level === 7 ? 8 : $state->level);
    }

    public function extract(ComposterState $state): ?ComposterResult
    {
        return $state->level === 8
            ? new ComposterResult(new ComposterState(0), false, new ContainerItemStack('minecraft:bone_meal', 1))
            : null;
    }

    private static function chance(string $identifier): ?int
    {
        if (isset(self::CHANCES[$identifier])) {
            return self::CHANCES[$identifier];
        }
        if (str_ends_with($identifier, '_leaves') || str_ends_with($identifier, '_sapling')) {
            return 30;
        }
        if (str_ends_with($identifier, '_flowers') || str_ends_with($identifier, '_mushroom')) {
            return 65;
        }
        return null;
    }
}
