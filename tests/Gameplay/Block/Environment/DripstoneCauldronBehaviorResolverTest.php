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

namespace Bedriox\Server\Tests\Gameplay\Block\Environment;

use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\Environment\DripstoneCauldronBehaviorResolver;
use Bedriox\Server\Gameplay\Processing\CauldronState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DripstoneCauldronBehaviorResolverTest extends TestCase
{
    public function testFindsWaterAboveABoundedHangingStalactite(): void
    {
        $source = (new DripstoneCauldronBehaviorResolver())->source([
            CanonicalBlockState::from('minecraft:air'),
            $this->dripstone('tip'),
            $this->dripstone('frustum'),
            CanonicalBlockState::from('minecraft:dripstone_block'),
            CanonicalBlockState::from('minecraft:water', ['liquid_depth' => 0]),
        ]);

        self::assertSame(CauldronContentType::WATER, $source);
    }

    public function testRejectsUprightDripstoneFlowingFluidAndObstructedColumns(): void
    {
        $resolver = new DripstoneCauldronBehaviorResolver();
        self::assertNull($resolver->source([
            CanonicalBlockState::from('minecraft:pointed_dripstone', [
                'dripstone_thickness' => 'tip',
                'hanging' => 0,
            ]),
            CanonicalBlockState::from('minecraft:dripstone_block'),
            CanonicalBlockState::from('minecraft:water', ['liquid_depth' => 0]),
        ]));
        self::assertNull($resolver->source([
            $this->dripstone('tip'),
            CanonicalBlockState::from('minecraft:stone'),
            CanonicalBlockState::from('minecraft:water', ['liquid_depth' => 0]),
        ]));
        self::assertNull($resolver->source([
            $this->dripstone('tip'),
            CanonicalBlockState::from('minecraft:dripstone_block'),
            CanonicalBlockState::from('minecraft:flowing_water', ['liquid_depth' => 3]),
        ]));
    }

    public function testWaterAddsOneLevelAndLavaFillsAnEmptyCauldron(): void
    {
        $resolver = new DripstoneCauldronBehaviorResolver();
        $water = $resolver->resolveFill(new CauldronState(CauldronContentType::WATER, 3), CauldronContentType::WATER);
        $lava = $resolver->resolveFill(CauldronState::empty(), CauldronContentType::LAVA);

        self::assertNotNull($water);
        self::assertNotNull($lava);
        self::assertSame(4, $water->level);
        self::assertSame(CauldronContentType::LAVA, $lava->content);
        self::assertSame(6, $lava->level);
        self::assertNull($resolver->resolveFill(new CauldronState(CauldronContentType::WATER, 6), CauldronContentType::WATER));
        self::assertNull($resolver->resolveFill(new CauldronState(CauldronContentType::WATER, 1), CauldronContentType::LAVA));
    }

    public function testRejectsAnUnboundedColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DripstoneCauldronBehaviorResolver())->source(array_fill(
            0,
            DripstoneCauldronBehaviorResolver::MAXIMUM_COLUMN_BLOCKS + 1,
            CanonicalBlockState::from('minecraft:air'),
        ));
    }

    private function dripstone(string $thickness): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:pointed_dripstone', [
            'dripstone_thickness' => $thickness,
            'hanging' => 1,
        ]);
    }
}
