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

namespace Bedriox\Server\Gameplay\Block\Environment;

use Bedriox\Data\CanonicalBlockState;

/** Classifies non-supporting blocks through which a falling block may begin or continue falling. */
final readonly class FallingBlockSupportResolver
{
    public function canFallThrough(CanonicalBlockState|string $block): bool
    {
        $identifier = $block instanceof CanonicalBlockState ? $block->identifier() : $block;
        if (in_array($identifier, [
            'minecraft:air',
            'minecraft:water',
            'minecraft:flowing_water',
            'minecraft:lava',
            'minecraft:flowing_lava',
            'minecraft:fire',
            'minecraft:soul_fire',
            'minecraft:tallgrass',
            'minecraft:short_grass',
            'minecraft:fern',
            'minecraft:deadbush',
            'minecraft:vine',
            'minecraft:snow_layer',
            'minecraft:redstone_wire',
            'minecraft:tripwire',
            'minecraft:torch',
            'minecraft:soul_torch',
        ], true)) {
            return true;
        }

        return str_ends_with($identifier, '_torch')
            || str_ends_with($identifier, '_rail')
            || str_ends_with($identifier, '_flower')
            || str_ends_with($identifier, '_sapling')
            || str_ends_with($identifier, '_carpet');
    }
}
