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
use Bedriox\Server\Gameplay\Block\ConnectedBlockStateResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class ConnectedBlockStateResolverTest extends TestCase
{
    public function testFenceConnectionsUseAllFourCanonicalDirections(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new ConnectedBlockStateResolver($states);
        $base = CanonicalBlockState::from('minecraft:oak_fence', [
            'minecraft:connection_east' => 0,
            'minecraft:connection_north' => 0,
            'minecraft:connection_south' => 0,
            'minecraft:connection_west' => 0,
        ]);

        $resolved = $states->state($resolver->resolve($base, [
            'north' => true,
            'east' => false,
            'south' => true,
            'west' => false,
        ]));
        self::assertSame(1, $resolved->properties()['minecraft:connection_north']);
        self::assertSame(1, $resolved->properties()['minecraft:connection_south']);
        self::assertSame(0, $resolved->properties()['minecraft:connection_east']);
        self::assertSame(0, $resolved->properties()['minecraft:connection_west']);
    }

    public function testStraightWallDropsItsPostAndPreservesTallConnections(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new ConnectedBlockStateResolver($states);
        $base = CanonicalBlockState::from('minecraft:cobblestone_wall', [
            'wall_connection_type_east' => 'none',
            'wall_connection_type_north' => 'none',
            'wall_connection_type_south' => 'none',
            'wall_connection_type_west' => 'none',
            'wall_post_bit' => 1,
        ]);

        $resolved = $states->state($resolver->resolve(
            $base,
            ['north' => true, 'east' => false, 'south' => true, 'west' => false],
            ['north' => true],
        ));
        self::assertSame('tall', $resolved->properties()['wall_connection_type_north']);
        self::assertSame('short', $resolved->properties()['wall_connection_type_south']);
        self::assertSame(0, $resolved->properties()['wall_post_bit']);
    }
}
