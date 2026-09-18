<?php

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
}
