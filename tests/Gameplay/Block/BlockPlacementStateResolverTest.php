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
use Bedriox\Server\Gameplay\Block\BlockPlacementStateResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlockPlacementStateResolverTest extends TestCase
{
    public function testEveryBlockFaceSelectsTheMatchingPillarAxis(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = GenerationBlockPalette::fromRegistry($registry);
        $resolver = new BlockPlacementStateResolver($registry);
        $base = $registry->state($palette->state('minecraft:oak_log'));

        foreach ([0 => 'y', 1 => 'y', 2 => 'z', 3 => 'z', 4 => 'x', 5 => 'x'] as $face => $axis) {
            $resolved = $registry->state($resolver->resolve($base, $face));
            self::assertSame('minecraft:oak_log', $resolved->identifier());
            self::assertSame($axis, $resolved->properties()['pillar_axis'] ?? null);
        }
    }

    public function testNonPillarBlockStateIsPreservedExactly(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new BlockPlacementStateResolver($registry);
        $state = CanonicalBlockState::from('minecraft:grass_block');

        self::assertSame($registry->internalId($state)->value, $resolver->resolve($state, 5)->value);
    }

    public function testEndPortalFrameFacesOppositeThePlacingPlayerWithoutAddingAnEye(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $resolver = new BlockPlacementStateResolver($registry);
        $base = CanonicalBlockState::from('minecraft:end_portal_frame', [
            'end_portal_eye_bit' => 0,
            'minecraft:cardinal_direction' => 'south',
        ]);

        foreach ([0.0 => 'north', 90.0 => 'east', 180.0 => 'south', 270.0 => 'west'] as $yaw => $direction) {
            $placed = $registry->state($resolver->resolve($base, 1, $yaw));
            self::assertSame($direction, $placed->properties()['minecraft:cardinal_direction']);
            self::assertSame(0, $placed->properties()['end_portal_eye_bit']);
        }
    }

    public function testMissingPillarVariantFailsDeterministically(): void
    {
        $vertical = CanonicalBlockState::from('minecraft:test_pillar', ['pillar_axis' => 'y']);
        $resolver = new BlockPlacementStateResolver(new BlockStateRegistry([$vertical]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Canonical block state is not registered internally.');
        $resolver->resolve($vertical, 5);
    }
}
