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

use Bedriox\Api\Processing\EnchantingOption;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;

/** Deterministically generates and applies the three bounded enchanting-table offers. */
final readonly class EnchantingProcessor
{
    private const int MAXIMUM_BOOKSHELVES = 15;

    /** @return list<EnchantingOption> */
    public function options(ContainerItemStack $item, int $bookshelves, int $seed): array
    {
        if ($bookshelves < 0 || $bookshelves > self::MAXIMUM_BOOKSHELVES || $seed < 0) {
            throw new InvalidArgumentException('Enchanting context is outside its supported bounds.');
        }
        if (WorkstationItemData::enchantments($item->nbt) !== []) {
            return [];
        }
        $pool = self::pool($item->identifier);
        if ($pool === []) {
            return [];
        }
        $options = [];
        for ($slot = 0; $slot < 3; ++$slot) {
            $entropy = self::entropy($seed, $item->identifier, $slot);
            $base = max(1, intdiv($bookshelves * 2 + 3, 3));
            $required = match ($slot) {
                0 => max(1, intdiv($base, 2) + ($entropy % max(1, intdiv($bookshelves, 2) + 1))),
                1 => max(1, $base + ($entropy % max(1, $bookshelves + 1))),
                2 => max($bookshelves * 2, $base + $bookshelves + ($entropy % max(1, $bookshelves + 1))),
            };
            $enchantments = [];
            $maximumOffers = $required >= 25 ? 3 : ($required >= 12 ? 2 : 1);
            for ($offset = 0; $offset < $maximumOffers; ++$offset) {
                $identifier = $pool[($entropy + $offset * 7) % count($pool)];
                if (isset($enchantments[$identifier]) || !self::compatible($identifier, array_keys($enchantments))) {
                    continue;
                }
                $enchantments[$identifier] = min(self::maximumLevel($identifier), max(1, intdiv($required, 10) + 1));
            }
            $options[] = new EnchantingOption($slot, min(30, $required), $slot + 1, $seed, $enchantments);
        }
        return $options;
    }

    public function apply(
        ContainerItemStack $item,
        EnchantingOption $selected,
        int $playerLevel,
        int $availableLapis,
    ): ?EnchantingProcessResult {
        if ($playerLevel < 0 || $availableLapis < 0) {
            throw new InvalidArgumentException('Enchanting balances cannot be negative.');
        }
        if ($playerLevel < $selected->requiredLevel || $availableLapis < $selected->lapisCost) {
            return null;
        }
        $merged = WorkstationItemData::enchantments($item->nbt);
        foreach ($selected->enchantments as $identifier => $level) {
            $merged[$identifier] = max($merged[$identifier] ?? 0, $level);
        }
        return new EnchantingProcessResult(
            new ContainerItemStack(
                $item->identifier === 'minecraft:book' ? 'minecraft:enchanted_book' : $item->identifier,
                1,
                $item->damage,
                WorkstationItemData::withEnchantments($item->nbt, $merged),
                $item->auxValue,
            ),
            $selected->lapisCost,
            $selected->lapisCost,
        );
    }

    /** @return list<string> */
    private static function pool(string $identifier): array
    {
        if ($identifier === 'minecraft:book') {
            return [
                'minecraft:unbreaking', 'minecraft:efficiency', 'minecraft:sharpness', 'minecraft:smite',
                'minecraft:bane_of_arthropods', 'minecraft:protection', 'minecraft:fire_protection',
                'minecraft:blast_protection', 'minecraft:projectile_protection', 'minecraft:power',
                'minecraft:fortune', 'minecraft:looting', 'minecraft:respiration',
            ];
        }
        if (str_ends_with($identifier, '_sword')) {
            return [
                'minecraft:sharpness', 'minecraft:smite', 'minecraft:bane_of_arthropods',
                'minecraft:knockback', 'minecraft:fire_aspect', 'minecraft:looting', 'minecraft:unbreaking',
            ];
        }
        if (str_ends_with($identifier, '_pickaxe') || str_ends_with($identifier, '_axe')
            || str_ends_with($identifier, '_shovel') || str_ends_with($identifier, '_hoe')) {
            return ['minecraft:efficiency', 'minecraft:silk_touch', 'minecraft:unbreaking', 'minecraft:fortune'];
        }
        foreach (['_helmet', '_chestplate', '_leggings', '_boots'] as $suffix) {
            if (str_ends_with($identifier, $suffix)) {
                $pool = [
                    'minecraft:protection', 'minecraft:fire_protection', 'minecraft:blast_protection',
                    'minecraft:projectile_protection', 'minecraft:unbreaking', 'minecraft:thorns',
                ];
                if ($suffix === '_helmet') {
                    $pool[] = 'minecraft:respiration';
                    $pool[] = 'minecraft:aqua_affinity';
                } elseif ($suffix === '_boots') {
                    $pool[] = 'minecraft:feather_falling';
                    $pool[] = 'minecraft:depth_strider';
                }
                return $pool;
            }
        }
        if ($identifier === 'minecraft:bow') {
            return ['minecraft:power', 'minecraft:unbreaking', 'minecraft:punch'];
        }
        if ($identifier === 'minecraft:crossbow') {
            return ['minecraft:quick_charge', 'minecraft:multishot', 'minecraft:piercing', 'minecraft:unbreaking'];
        }
        if ($identifier === 'minecraft:trident') {
            return ['minecraft:impaling', 'minecraft:loyalty', 'minecraft:riptide', 'minecraft:channeling', 'minecraft:unbreaking'];
        }
        if ($identifier === 'minecraft:fishing_rod') {
            return ['minecraft:luck_of_the_sea', 'minecraft:lure', 'minecraft:unbreaking'];
        }
        if ($identifier === 'minecraft:mace') {
            return ['minecraft:density', 'minecraft:breach', 'minecraft:unbreaking'];
        }
        return [];
    }

    private static function maximumLevel(string $identifier): int
    {
        return match ($identifier) {
            'minecraft:unbreaking', 'minecraft:looting', 'minecraft:fortune', 'minecraft:thorns',
            'minecraft:respiration', 'minecraft:depth_strider', 'minecraft:quick_charge',
            'minecraft:loyalty', 'minecraft:riptide', 'minecraft:luck_of_the_sea', 'minecraft:lure',
            'minecraft:density', 'minecraft:breach' => 3,
            'minecraft:punch', 'minecraft:fire_aspect', 'minecraft:knockback' => 2,
            'minecraft:silk_touch', 'minecraft:aqua_affinity', 'minecraft:multishot',
            'minecraft:channeling' => 1,
            default => 5,
        };
    }

    /** @param list<string> $selected */
    private static function compatible(string $candidate, array $selected): bool
    {
        $groups = [
            ['minecraft:sharpness', 'minecraft:smite', 'minecraft:bane_of_arthropods'],
            ['minecraft:protection', 'minecraft:fire_protection', 'minecraft:blast_protection', 'minecraft:projectile_protection'],
            ['minecraft:silk_touch', 'minecraft:fortune'],
            ['minecraft:multishot', 'minecraft:piercing'],
            ['minecraft:loyalty', 'minecraft:riptide'],
            ['minecraft:density', 'minecraft:breach'],
        ];
        foreach ($groups as $group) {
            if (!in_array($candidate, $group, true)) {
                continue;
            }
            foreach ($selected as $identifier) {
                if (in_array($identifier, $group, true)) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function entropy(int $seed, string $identifier, int $slot): int
    {
        $hash = hash('sha256', $seed . "\0" . $identifier . "\0" . $slot, true);
        $decoded = unpack('Vvalue', substr($hash, 0, 4));
        if ($decoded === false || !is_int($decoded['value'] ?? null)) {
            throw new \LogicException('Unable to decode enchanting entropy.');
        }
        return $decoded['value'] & 0x7fffffff;
    }
}
