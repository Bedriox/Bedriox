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
use Bedriox\Server\Gameplay\Block\Interaction\InteractiveBlockStateResolver;
use Bedriox\Server\Gameplay\Block\Interaction\PressurePlateActivationType;
use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InteractiveBlockStateResolverTest extends TestCase
{
    private BlockStateRegistry $states;
    private InteractiveBlockStateResolver $resolver;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $this->resolver = new InteractiveBlockStateResolver($this->states);
    }

    public function testTrapdoorsToggleWithoutChangingTheirPlacementState(): void
    {
        $closed = CanonicalBlockState::from('minecraft:trapdoor', [
            'direction' => 3,
            'open_bit' => 0,
            'upside_down_bit' => 1,
        ]);

        $open = $this->states->state($this->resolver->toggleOpen($closed));

        self::assertSame(1, $open->properties()['open_bit']);
        self::assertSame(3, $open->properties()['direction']);
        self::assertSame(1, $open->properties()['upside_down_bit']);
        self::assertTrue($this->resolver->canPlayerToggle($closed));
        self::assertFalse($this->resolver->canPlayerToggle(CanonicalBlockState::from('minecraft:iron_trapdoor', [
            'direction' => 3,
            'open_bit' => 0,
            'upside_down_bit' => 0,
        ])));
    }

    public function testTrapdoorPlacementUsesPlayerDirectionAndClickedHalf(): void
    {
        $base = CanonicalBlockState::from('minecraft:trapdoor', [
            'direction' => 0,
            'open_bit' => 1,
            'upside_down_bit' => 0,
        ]);

        $top = $this->states->state($this->resolver->placeTrapdoor(
            $base,
            new BlockPlacementContext(5, 0.5, 0.75, 0.5, 0.0),
        ));
        $bottom = $this->states->state($this->resolver->placeTrapdoor(
            $base,
            new BlockPlacementContext(1, 0.5, 0.9, 0.5, 90.0),
        ));

        self::assertSame(3, $top->properties()['direction']);
        self::assertSame(1, $top->properties()['upside_down_bit']);
        self::assertSame(0, $top->properties()['open_bit']);
        self::assertSame(0, $bottom->properties()['direction']);
        self::assertSame(0, $bottom->properties()['upside_down_bit']);
    }

    public function testFenceGateOpeningFromBehindUsesThePlayerDirection(): void
    {
        $closed = CanonicalBlockState::from('minecraft:fence_gate', [
            'in_wall_bit' => 1,
            'minecraft:cardinal_direction' => 'south',
            'open_bit' => 0,
        ]);

        $open = $this->states->state($this->resolver->toggleOpen($closed, 'north'));

        self::assertSame(1, $open->properties()['open_bit']);
        self::assertSame(1, $open->properties()['in_wall_bit']);
        self::assertSame('north', $open->properties()['minecraft:cardinal_direction']);
    }

    public function testFenceGatePlacementRecordsDirectionAndAdjacentWallState(): void
    {
        $base = CanonicalBlockState::from('minecraft:fence_gate', [
            'in_wall_bit' => 0,
            'minecraft:cardinal_direction' => 'north',
            'open_bit' => 1,
        ]);

        $placed = $this->states->state($this->resolver->placeFenceGate(
            $base,
            new BlockPlacementContext(1, 0.5, 0.5, 0.5, 90.0),
            true,
            false,
        ));

        self::assertSame('west', $placed->properties()['minecraft:cardinal_direction']);
        self::assertSame(1, $placed->properties()['in_wall_bit']);
        self::assertSame(0, $placed->properties()['open_bit']);
    }

    public function testButtonsUseTheirVanillaPressedStateAndReleaseDelay(): void
    {
        $stone = CanonicalBlockState::from('minecraft:stone_button', [
            'button_pressed_bit' => 0,
            'facing_direction' => 4,
        ]);
        $wood = CanonicalBlockState::from('minecraft:wooden_button', [
            'button_pressed_bit' => 0,
            'facing_direction' => 4,
        ]);

        $pressed = $this->states->state($this->resolver->setButtonPressed($stone, true));

        self::assertSame(1, $pressed->properties()['button_pressed_bit']);
        self::assertSame(4, $pressed->properties()['facing_direction']);
        self::assertSame(20, $this->resolver->buttonReleaseTicks($stone));
        self::assertSame(30, $this->resolver->buttonReleaseTicks($wood));
    }

    public function testPressurePlateRulesDistinguishLivingAndWeightedActivation(): void
    {
        $stone = CanonicalBlockState::from('minecraft:stone_pressure_plate', ['redstone_signal' => 0]);
        $wood = CanonicalBlockState::from('minecraft:wooden_pressure_plate', ['redstone_signal' => 0]);
        $light = CanonicalBlockState::from('minecraft:light_weighted_pressure_plate', ['redstone_signal' => 0]);
        $heavy = CanonicalBlockState::from('minecraft:heavy_weighted_pressure_plate', ['redstone_signal' => 0]);

        self::assertSame(
            PressurePlateActivationType::LIVING_ENTITIES,
            $this->resolver->pressurePlateActivationType($stone),
        );
        self::assertSame(
            PressurePlateActivationType::ALL_ENTITIES,
            $this->resolver->pressurePlateActivationType($wood),
        );
        self::assertSame(0, $this->signal($stone, 0, 1));
        self::assertSame(15, $this->signal($stone, 1, 1));
        self::assertSame(15, $this->signal($wood, 0, 1));
        self::assertSame(7, $this->signal($light, 0, 7));
        self::assertSame(15, $this->signal($light, 0, 18));
        self::assertSame(2, $this->signal($heavy, 0, 11));
        self::assertSame(20, $this->resolver->pressurePlateReleaseTicks($stone));
        self::assertSame(10, $this->resolver->pressurePlateReleaseTicks($heavy));
    }

    public function testInvalidPressurePlateCountsFailBeforeStateLookup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pressure plate entity counts are inconsistent.');

        $this->resolver->pressurePlateSignal(
            CanonicalBlockState::from('minecraft:wooden_pressure_plate', ['redstone_signal' => 0]),
            2,
            1,
        );
    }

    private function signal(CanonicalBlockState $state, int $livingCount, int $allCount): int
    {
        $signal = $this->states->state(
            $this->resolver->pressurePlateSignal($state, $livingCount, $allCount),
        )->properties()['redstone_signal'];
        self::assertIsInt($signal);

        return $signal;
    }
}
