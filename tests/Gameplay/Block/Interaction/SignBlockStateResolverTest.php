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

namespace Bedriox\Server\Tests\Gameplay\Block\Interaction;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\BlockPlacementContext;
use Bedriox\Server\Gameplay\Block\Interaction\SignBlockStateResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SignBlockStateResolverTest extends TestCase
{
    private BlockStateRegistry $states;
    private SignBlockStateResolver $resolver;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $this->resolver = new SignBlockStateResolver($this->states);
    }

    public function testOrdinarySignSelectsStandingOrWallStateFromClickedFace(): void
    {
        $standing = CanonicalBlockState::from('minecraft:standing_sign', ['ground_sign_direction' => 0]);
        $floor = $this->states->state($this->resolver->place(
            $standing,
            new BlockPlacementContext(1, 0.5, 0.5, 0.5, 0.0),
        ));
        self::assertSame('minecraft:standing_sign', $floor->identifier());
        self::assertSame(8, $floor->properties()['ground_sign_direction']);

        $wall = $this->states->state($this->resolver->place(
            $standing,
            new BlockPlacementContext(5, 0.5, 0.5, 0.5, 90.0),
        ));
        self::assertSame('minecraft:wall_sign', $wall->identifier());
        self::assertSame(5, $wall->properties()['facing_direction']);
    }

    public function testWoodVariantConvertsToItsMatchingWallIdentifier(): void
    {
        $state = CanonicalBlockState::from('minecraft:spruce_standing_sign', ['ground_sign_direction' => 0]);
        $placed = $this->states->state($this->resolver->place(
            $state,
            new BlockPlacementContext(2, 0.5, 0.5, 0.5, 0.0),
        ));
        self::assertSame('minecraft:spruce_wall_sign', $placed->identifier());
    }

    public function testHangingSignUsesCeilingOrSideCanonicalState(): void
    {
        $state = CanonicalBlockState::from('minecraft:oak_hanging_sign', [
            'attached_bit' => 0,
            'facing_direction' => 0,
            'ground_sign_direction' => 0,
            'hanging' => 0,
        ]);
        $ceiling = $this->states->state($this->resolver->place(
            $state,
            new BlockPlacementContext(0, 0.5, 0.5, 0.5, 90.0),
        ));
        self::assertSame(1, $ceiling->properties()['attached_bit']);
        self::assertSame(1, $ceiling->properties()['hanging']);
        self::assertSame(2, $ceiling->properties()['facing_direction']);
        self::assertSame(12, $ceiling->properties()['ground_sign_direction']);

        $wall = $this->states->state($this->resolver->place(
            $state,
            new BlockPlacementContext(4, 0.5, 0.5, 0.5, 0.0),
        ));
        self::assertSame(2, $wall->properties()['facing_direction']);
        self::assertSame(0, $wall->properties()['hanging']);
    }

    public function testWallHangingSignUsesPerpendicularBoardDirectionAndFacesThePlayer(): void
    {
        $state = CanonicalBlockState::from('minecraft:oak_hanging_sign', [
            'attached_bit' => 0,
            'facing_direction' => 0,
            'ground_sign_direction' => 0,
            'hanging' => 0,
        ]);

        foreach ([2 => 5, 3 => 4, 4 => 2, 5 => 2] as $supportFace => $expectedDirection) {
            $placed = $this->states->state($this->resolver->place(
                $state,
                new BlockPlacementContext($supportFace, 0.5, 0.5, 0.5, 0.0),
            ));
            self::assertSame($expectedDirection, $placed->properties()['facing_direction']);
        }

        $turnedTowardPlayer = $this->states->state($this->resolver->place(
            $state,
            new BlockPlacementContext(5, 0.5, 0.5, 0.5, 0.0),
        ));
        self::assertSame(2, $turnedTowardPlayer->properties()['facing_direction']);
    }

    public function testInvalidSupportFaceFailsBeforeRegistryLookup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->place(
            CanonicalBlockState::from('minecraft:standing_sign', ['ground_sign_direction' => 0]),
            new BlockPlacementContext(0, 0.5, 0.5, 0.5, 0.0),
        );
    }
}
