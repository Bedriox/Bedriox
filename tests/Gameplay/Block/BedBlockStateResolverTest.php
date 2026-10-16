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
use Bedriox\Server\Gameplay\Block\BedBlockStateResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class BedBlockStateResolverTest extends TestCase
{
    public function testPlacementCreatesMatchingFootAndHeadForEveryDirection(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new BedBlockStateResolver($states);
        $foot = new BlockPosition(10, 64, 10);

        foreach ([[0.0, 0, 10, 11], [90.0, 1, 9, 10], [180.0, 2, 10, 9], [270.0, 3, 11, 10]] as [$yaw, $direction, $headX, $headZ]) {
            $placement = $resolver->placement($foot, $yaw);
            $footState = $states->state($placement['foot']);
            $headState = $states->state($placement['head']);

            self::assertSame($direction, $footState->properties()['direction']);
            self::assertSame(0, $footState->properties()['head_piece_bit']);
            self::assertSame(0, $footState->properties()['occupied_bit']);
            self::assertSame(1, $headState->properties()['head_piece_bit']);
            self::assertSame($headX, $placement['headPosition']->x);
            self::assertSame($headZ, $placement['headPosition']->z);
            self::assertTrue($resolver->isMatchingHalf($footState, $headState));
            self::assertTrue($resolver->otherHalf($placement['headPosition'], $headState)->equals($foot));
        }
    }

    public function testOccupiedTransitionPreservesDirectionAndHalf(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new BedBlockStateResolver($states);
        $placement = $resolver->placement(new BlockPosition(0, 70, 0), 0.0);
        $head = $states->state($placement['head']);
        $occupied = $states->state($resolver->withOccupied($head, true));

        self::assertSame(0, $occupied->properties()['direction']);
        self::assertSame(1, $occupied->properties()['head_piece_bit']);
        self::assertSame(1, $occupied->properties()['occupied_bit']);
    }
}
