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

/** Computes bounded rename, same-item repair and enchantment-combine proposals. */
final readonly class AnvilProcessor
{
    public function process(
        ContainerItemStack $primary,
        ?ContainerItemStack $secondary,
        ?string $name,
        int $maximumDurability,
    ): ?WorkstationResult {
        if ($maximumDurability < 0 || $maximumDurability > 65_535) {
            throw new InvalidArgumentException('Anvil maximum durability is outside its supported range.');
        }
        $damage = $primary->damage;
        $enchantments = WorkstationItemData::enchantments($primary->nbt);
        $cost = WorkstationItemData::repairCost($primary->nbt);
        $consumed = [0 => 1];
        $changed = false;
        if ($secondary !== null) {
            $secondaryConsumption = 1;
            if ($secondary->identifier === $primary->identifier && $maximumDurability > 0) {
                $remainingPrimary = max(0, $maximumDurability - $primary->damage);
                $remainingSecondary = max(0, $maximumDurability - $secondary->damage);
                $bonus = intdiv($maximumDurability * 12, 100);
                $newDamage = max(0, $maximumDurability - min($maximumDurability, $remainingPrimary + $remainingSecondary + $bonus));
                $changed = $newDamage !== $damage;
                $damage = $newDamage;
            } elseif ($maximumDurability > 0 && self::isRepairMaterial($primary->identifier, $secondary->identifier)) {
                $restoredPerItem = max(1, intdiv($maximumDurability, 4));
                $secondaryConsumption = min($secondary->count, max(1, (int) ceil($damage / $restoredPerItem)));
                $newDamage = max(0, $damage - $restoredPerItem * $secondaryConsumption);
                $changed = $newDamage !== $damage;
                $damage = $newDamage;
            } elseif ($secondary->identifier !== 'minecraft:enchanted_book') {
                return null;
            }
            foreach (WorkstationItemData::enchantments($secondary->nbt) as $identifier => $level) {
                $current = $enchantments[$identifier] ?? 0;
                $enchantments[$identifier] = $current === $level ? min(255, $level + 1) : max($current, $level);
                $changed = true;
            }
            $cost += 1 + WorkstationItemData::repairCost($secondary->nbt);
            $consumed[1] = $secondaryConsumption;
        }
        $nbt = WorkstationItemData::withDisplayName($primary->nbt, $name);
        if (($name ?? '') !== (WorkstationItemData::displayName($primary->nbt) ?? '')) {
            ++$cost;
            $changed = true;
        }
        if ($enchantments !== WorkstationItemData::enchantments($primary->nbt)) {
            $nbt = WorkstationItemData::withEnchantments($nbt, $enchantments);
        }
        if (!$changed || $cost > 39) {
            return null;
        }
        $nbt = WorkstationItemData::withRepairCost($nbt, min(32_767, $cost * 2 + 1));
        return new WorkstationResult(
            $consumed,
            [new ContainerItemStack($primary->identifier, 1, $damage, $nbt, $primary->auxValue)],
            experienceLevelCost: $cost,
        );
    }

    private static function isRepairMaterial(string $identifier, string $material): bool
    {
        if (!str_starts_with($identifier, 'minecraft:')) {
            return false;
        }
        $path = substr($identifier, strlen('minecraft:'));
        return match (true) {
            str_starts_with($path, 'wooden_') => str_ends_with($material, '_planks'),
            str_starts_with($path, 'stone_') => $material === 'minecraft:cobblestone',
            str_starts_with($path, 'copper_') => $material === 'minecraft:copper_ingot',
            str_starts_with($path, 'iron_') => $material === 'minecraft:iron_ingot',
            str_starts_with($path, 'golden_') => $material === 'minecraft:gold_ingot',
            str_starts_with($path, 'diamond_') => $material === 'minecraft:diamond',
            str_starts_with($path, 'netherite_') => $material === 'minecraft:netherite_ingot',
            str_starts_with($path, 'leather_') => $material === 'minecraft:leather',
            str_starts_with($path, 'chainmail_') => $material === 'minecraft:iron_ingot',
            str_starts_with($path, 'turtle_') => $material === 'minecraft:scute',
            $path === 'elytra' => $material === 'minecraft:phantom_membrane',
            default => false,
        };
    }
}
