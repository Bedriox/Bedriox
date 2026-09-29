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

/** Removes non-curse processing enchantments and optionally combines equal durable items. */
final readonly class GrindstoneProcessor
{
    public function process(
        ContainerItemStack $first,
        ?ContainerItemStack $second,
        int $maximumDurability,
    ): ?WorkstationResult {
        if ($maximumDurability < 0 || $maximumDurability > 65_535) {
            throw new InvalidArgumentException('Grindstone maximum durability is outside its supported range.');
        }
        $damage = $first->damage;
        $consumed = [0 => 1];
        if ($second !== null) {
            if ($second->identifier !== $first->identifier || $maximumDurability === 0) {
                return null;
            }
            $remaining = ($maximumDurability - $first->damage) + ($maximumDurability - $second->damage);
            $damage = max(0, $maximumDurability - min($maximumDurability, $remaining + intdiv($maximumDurability * 5, 100)));
            $consumed[1] = 1;
        }
        $allEnchantments = WorkstationItemData::enchantments($first->nbt);
        foreach (WorkstationItemData::enchantments($second?->nbt) as $identifier => $level) {
            $allEnchantments[$identifier] = max($allEnchantments[$identifier] ?? 0, $level);
        }
        $removed = [];
        $retained = [];
        foreach ($allEnchantments as $identifier => $level) {
            if (in_array($identifier, ['minecraft:binding', 'minecraft:vanishing'], true)) {
                $retained[$identifier] = $level;
            } else {
                $removed[$identifier] = $level;
            }
        }
        if ($second === null && $removed === []) {
            return null;
        }
        $experience = 0;
        foreach ($removed as $level) {
            $experience += max(1, $level);
        }
        $nbt = WorkstationItemData::withoutEnchantments($first->nbt);
        if ($retained !== []) {
            $nbt = WorkstationItemData::withEnchantments($nbt, $retained);
        }
        return new WorkstationResult(
            $consumed,
            [new ContainerItemStack(
                $first->identifier,
                1,
                $damage,
                $nbt,
                $first->auxValue,
            )],
            min(1_000_000, $experience),
        );
    }
}
