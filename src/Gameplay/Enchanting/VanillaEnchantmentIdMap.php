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

/** Canonical mapping between Bedriox enchantment identifiers and Bedrock serialized IDs. */
final class VanillaEnchantmentIdMap
{
    private const array IDS = [
        'minecraft:protection' => 0, 'minecraft:fire_protection' => 1,
        'minecraft:feather_falling' => 2, 'minecraft:blast_protection' => 3,
        'minecraft:projectile_protection' => 4, 'minecraft:thorns' => 5,
        'minecraft:respiration' => 6, 'minecraft:depth_strider' => 7,
        'minecraft:aqua_affinity' => 8, 'minecraft:sharpness' => 9,
        'minecraft:smite' => 10, 'minecraft:bane_of_arthropods' => 11,
        'minecraft:knockback' => 12, 'minecraft:fire_aspect' => 13,
        'minecraft:looting' => 14, 'minecraft:efficiency' => 15,
        'minecraft:silk_touch' => 16, 'minecraft:unbreaking' => 17,
        'minecraft:fortune' => 18, 'minecraft:power' => 19,
        'minecraft:punch' => 20, 'minecraft:flame' => 21,
        'minecraft:infinity' => 22, 'minecraft:luck_of_the_sea' => 23,
        'minecraft:lure' => 24, 'minecraft:frost_walker' => 25,
        'minecraft:mending' => 26, 'minecraft:binding' => 27,
        'minecraft:vanishing' => 28, 'minecraft:impaling' => 29,
        'minecraft:riptide' => 30, 'minecraft:loyalty' => 31,
        'minecraft:channeling' => 32, 'minecraft:multishot' => 33,
        'minecraft:piercing' => 34, 'minecraft:quick_charge' => 35,
        'minecraft:soul_speed' => 36, 'minecraft:swift_sneak' => 37,
        'minecraft:wind_burst' => 38, 'minecraft:density' => 39,
        'minecraft:breach' => 40, 'minecraft:lunge' => 41,
    ];

    /** @var array<int, string>|null */
    private static ?array $identifiers = null;

    public static function id(string $identifier): ?int
    {
        return self::IDS[$identifier] ?? null;
    }

    public static function identifier(int $id): ?string
    {
        return (self::$identifiers ??= array_flip(self::IDS))[$id] ?? null;
    }

    private function __construct() {}
}
