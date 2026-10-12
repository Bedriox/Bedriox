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

namespace Bedriox\Server\Tests\Gameplay\Block;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\StairBlockStateResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class StairBlockStateResolverTest extends TestCase
{
    public function testPerpendicularForwardStairProducesAnOuterCorner(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new StairBlockStateResolver($states);
        $air = CanonicalBlockState::from('minecraft:air');
        $current = self::stair(3);

        $resolved = $states->state($resolver->resolve($current, [
            'north' => self::stair(1),
            'east' => $air,
            'south' => $air,
            'west' => $air,
        ]));

        self::assertSame('outer_left', $resolved->properties()['minecraft:corner']);
    }

    public function testUnrelatedNeighborsPreserveStraightShape(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new StairBlockStateResolver($states);
        $air = CanonicalBlockState::from('minecraft:air');

        $resolved = $states->state($resolver->resolve(self::stair(2), [
            'north' => $air,
            'east' => $air,
            'south' => $air,
            'west' => $air,
        ]));

        self::assertSame('none', $resolved->properties()['minecraft:corner']);
    }

    private static function stair(int $direction): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:oak_stairs', [
            'minecraft:corner' => 'none',
            'upside_down_bit' => 0,
            'weirdo_direction' => $direction,
        ]);
    }
}
