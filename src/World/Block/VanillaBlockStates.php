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

namespace Bedriox\Server\World\Block;

use Bedriox\Data\CanonicalBlockState;

/** Canonical vanilla states used by the current authoritative simulation. */
final class VanillaBlockStates
{
    private function __construct() {}

    public static function air(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:air');
    }
    public static function bedrock(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:bedrock', ['infiniburn_bit' => 0]);
    }
    public static function dirt(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:dirt');
    }
    public static function grassBlock(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:grass_block');
    }

    public static function stone(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:stone');
    }
    public static function cobblestone(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:cobblestone');
    }
    public static function cobbledDeepslate(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:cobbled_deepslate');
    }

    public static function sand(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:sand');
    }

    public static function sandstone(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:sandstone');
    }

    public static function gravel(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:gravel');
    }

    public static function water(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:water', ['liquid_depth' => 0]);
    }

    public static function coalOre(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:coal_ore');
    }

    public static function ironOre(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:iron_ore');
    }

    public static function oakLog(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:oak_log', ['pillar_axis' => 'y']);
    }

    public static function oakLeaves(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:oak_leaves', ['persistent_bit' => 0, 'update_bit' => 0]);
    }

    public static function clay(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:clay');
    }

    public static function ice(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:ice');
    }

    public static function snow(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:snow');
    }

    public static function coarseDirt(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:coarse_dirt');
    }

    public static function podzol(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:podzol');
    }

    public static function deepslate(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:deepslate', ['pillar_axis' => 'y']);
    }

    public static function lava(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:lava', ['liquid_depth' => 0]);
    }

    public static function copperOre(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:copper_ore');
    }

    public static function goldOre(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:gold_ore');
    }

    public static function redstoneOre(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:redstone_ore');
    }

    public static function diamondOre(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:diamond_ore');
    }

    public static function birchLog(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:birch_log', ['pillar_axis' => 'y']);
    }

    public static function birchLeaves(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:birch_leaves', ['persistent_bit' => 0, 'update_bit' => 0]);
    }

    public static function spruceLog(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:spruce_log', ['pillar_axis' => 'y']);
    }

    public static function spruceLeaves(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:spruce_leaves', ['persistent_bit' => 0, 'update_bit' => 0]);
    }
}
