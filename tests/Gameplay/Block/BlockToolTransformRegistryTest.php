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
use Bedriox\Server\Gameplay\Block\BlockToolTransformRegistry;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class BlockToolTransformRegistryTest extends TestCase
{
    private BlockStateRegistry $states;
    private BlockToolTransformRegistry $transforms;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $this->transforms = new BlockToolTransformRegistry($this->states);
    }

    public function testAxePreservesLogAxisWhenStripping(): void
    {
        $result = $this->transforms->resolve(
            CanonicalBlockState::from('minecraft:oak_log', ['pillar_axis' => 'x']),
            'minecraft:iron_axe',
            1,
            true,
        );

        self::assertNotNull($result);
        $state = $this->states->state($result->state);
        self::assertSame('minecraft:stripped_oak_log', $state->identifier());
        self::assertSame('x', $state->properties()['pillar_axis']);
    }

    public function testHoeProducesFarmlandAndRootedDirtDrop(): void
    {
        $farmland = $this->transforms->resolve(
            CanonicalBlockState::from('minecraft:grass_block'),
            'minecraft:diamond_hoe',
            1,
            true,
        );
        self::assertNotNull($farmland);
        self::assertSame('minecraft:farmland', $this->states->state($farmland->state)->identifier());

        $rooted = $this->transforms->resolve(
            CanonicalBlockState::from('minecraft:rooted_dirt'),
            'minecraft:wooden_hoe',
            1,
            true,
        );
        self::assertNotNull($rooted);
        self::assertSame('minecraft:dirt', $this->states->state($rooted->state)->identifier());
        self::assertSame('minecraft:hanging_roots', $rooted->dropIdentifier);
    }

    public function testTransformRejectsBlockedTopAndWrongTool(): void
    {
        $state = CanonicalBlockState::from('minecraft:dirt');
        self::assertNull($this->transforms->resolve($state, 'minecraft:iron_shovel', 1, false));
        self::assertNull($this->transforms->resolve($state, 'minecraft:iron_pickaxe', 1, true));
        self::assertNull($this->transforms->resolve($state, 'minecraft:iron_shovel', 0, true));
    }
}
