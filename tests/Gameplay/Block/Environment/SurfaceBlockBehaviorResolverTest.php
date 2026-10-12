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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\Environment\SurfaceBlockBehaviorResolver;
use Bedriox\Server\Gameplay\Block\Environment\SurfaceSpreadCandidate;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SurfaceBlockBehaviorResolverTest extends TestCase
{
    private BlockStateRegistry $states;
    private SurfaceBlockBehaviorResolver $resolver;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $this->resolver = new SurfaceBlockBehaviorResolver($this->states);
    }

    public function testCoveredGrassAndMyceliumDecayToDirt(): void
    {
        foreach (['minecraft:grass_block', 'minecraft:mycelium'] as $identifier) {
            $result = $this->resolver->resolve(
                new BlockPosition(1, 64, 2),
                CanonicalBlockState::from($identifier),
                true,
                [],
            );

            self::assertCount(1, $result);
            self::assertSame('minecraft:dirt', $this->states->state($result[0]->state)->identifier());
        }
    }

    public function testGrassSpreadsOnlyToExposedOrdinaryDirt(): void
    {
        $result = $this->resolver->resolve(
            new BlockPosition(0, 64, 0),
            CanonicalBlockState::from('minecraft:grass_block'),
            false,
            [
                new SurfaceSpreadCandidate(new BlockPosition(1, 64, 0), CanonicalBlockState::from('minecraft:dirt'), false),
                new SurfaceSpreadCandidate(new BlockPosition(2, 64, 0), CanonicalBlockState::from('minecraft:dirt'), true),
                new SurfaceSpreadCandidate(new BlockPosition(3, 64, 0), CanonicalBlockState::from('minecraft:coarse_dirt'), false),
                new SurfaceSpreadCandidate(new BlockPosition(4, 64, 0), CanonicalBlockState::from('minecraft:dirt'), false),
            ],
        );

        self::assertCount(2, $result);
        self::assertSame(1, $result[0]->position->x);
        self::assertSame(4, $result[1]->position->x);
        foreach ($result as $transition) {
            self::assertSame('minecraft:grass_block', $this->states->state($transition->state)->identifier());
        }
    }

    public function testMyceliumUsesOneBoundedSpreadProbe(): void
    {
        $result = $this->resolver->resolve(
            new BlockPosition(0, 64, 0),
            CanonicalBlockState::from('minecraft:mycelium'),
            false,
            [
                new SurfaceSpreadCandidate(new BlockPosition(1, 64, 0), CanonicalBlockState::from('minecraft:dirt'), false),
                new SurfaceSpreadCandidate(new BlockPosition(2, 64, 0), CanonicalBlockState::from('minecraft:dirt'), false),
            ],
        );

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]->position->x);
        self::assertSame('minecraft:mycelium', $this->states->state($result[0]->state)->identifier());
    }

    public function testRejectsMoreThanFourSpreadProbes(): void
    {
        $candidates = [];
        for ($x = 0; $x < 5; ++$x) {
            $candidates[] = new SurfaceSpreadCandidate(
                new BlockPosition($x, 64, 0),
                CanonicalBlockState::from('minecraft:dirt'),
                false,
            );
        }

        $this->expectException(InvalidArgumentException::class);
        $this->resolver->resolve(
            new BlockPosition(0, 64, 0),
            CanonicalBlockState::from('minecraft:grass_block'),
            false,
            $candidates,
        );
    }
}
