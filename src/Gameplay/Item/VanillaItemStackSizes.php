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

/** Current vanilla item stack limits which differ from the common limit of 64. */
final class VanillaItemStackSizes
{
    private const array SIXTEEN = [
        'minecraft:armor_stand' => true,
        'minecraft:banner' => true,
        'minecraft:blue_egg' => true,
        'minecraft:brown_egg' => true,
        'minecraft:bucket' => true,
        'minecraft:egg' => true,
        'minecraft:ender_pearl' => true,
        'minecraft:honey_bottle' => true,
        'minecraft:hanging_sign' => true,
        'minecraft:ice_bomb' => true,
        'minecraft:snowball' => true,
        'minecraft:sign' => true,
        'minecraft:written_book' => true,
    ];

    private const array ONE = [
        'minecraft:banner_pattern' => true,
        'minecraft:bed' => true,
        'minecraft:beetroot_soup' => true,
        'minecraft:boat' => true,
        'minecraft:bundle' => true,
        'minecraft:cake' => true,
        'minecraft:carrot_on_a_stick' => true,
        'minecraft:chest_boat' => true,
        'minecraft:elytra' => true,
        'minecraft:enchanted_book' => true,
        'minecraft:filled_map' => true,
        'minecraft:flint_and_steel' => true,
        'minecraft:goat_horn' => true,
        'minecraft:lingering_potion' => true,
        'minecraft:mace' => true,
        'minecraft:map' => true,
        'minecraft:medicine' => true,
        'minecraft:milk_bucket' => true,
        'minecraft:minecart' => true,
        'minecraft:mushroom_stew' => true,
        'minecraft:potion' => true,
        'minecraft:rabbit_stew' => true,
        'minecraft:saddle' => true,
        'minecraft:shield' => true,
        'minecraft:splash_potion' => true,
        'minecraft:spyglass' => true,
        'minecraft:suspicious_stew' => true,
        'minecraft:totem_of_undying' => true,
        'minecraft:warped_fungus_on_a_stick' => true,
        'minecraft:writable_book' => true,
    ];

    public static function for(string $identifier): int
    {
        if (isset(self::SIXTEEN[$identifier]) || self::hasSuffix($identifier, '_sign')
            || self::hasSuffix($identifier, '_hanging_sign') || self::hasSuffix($identifier, '_banner')) {
            return 16;
        }
        if (isset(self::ONE[$identifier]) || self::isFilledBucket($identifier)
            || self::hasSuffix($identifier, '_bed') || self::hasSuffix($identifier, '_boat')
            || self::hasSuffix($identifier, '_chest_boat') || self::hasSuffix($identifier, '_raft')
            || self::hasSuffix($identifier, '_chest_raft') || self::hasSuffix($identifier, '_minecart')
            || self::hasSuffix($identifier, '_shulker_box') || self::hasSuffix($identifier, '_horse_armor')
            || self::hasSuffix($identifier, '_harness') || self::hasSuffix($identifier, '_bundle')
            || self::hasSuffix($identifier, '_banner_pattern') || str_starts_with($identifier, 'minecraft:music_disc_')
            || str_starts_with($identifier, 'minecraft:record_')) {
            return 1;
        }

        return 64;
    }

    private static function isFilledBucket(string $identifier): bool
    {
        return $identifier !== 'minecraft:bucket' && self::hasSuffix($identifier, '_bucket');
    }

    private static function hasSuffix(string $identifier, string $suffix): bool
    {
        return str_starts_with($identifier, 'minecraft:') && str_ends_with($identifier, $suffix);
    }

    private function __construct() {}
}
