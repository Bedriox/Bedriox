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
use Bedriox\Server\Gameplay\Block\Interaction\DoorBlockStateResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DoorBlockStateResolverTest extends TestCase
{
    private BlockStateRegistry $states;
    private DoorBlockStateResolver $resolver;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $this->resolver = new DoorBlockStateResolver($this->states);
    }

    public function testPlacementCreatesMatchingLowerAndUpperHalves(): void
    {
        $pair = $this->resolver->place(
            $this->doorState('minecraft:wooden_door', 0, 0, 0, 'south'),
            new BlockPlacementContext(1, 0.5, 0.5, 0.5, 0.0),
            false,
            false,
            false,
        );
        $lower = $this->states->state($pair->lower);
        $upper = $this->states->state($pair->upper);

        self::assertSame(0, $lower->properties()['upper_block_bit']);
        self::assertSame(1, $upper->properties()['upper_block_bit']);
        self::assertSame('west', $lower->properties()['minecraft:cardinal_direction']);
        self::assertSame($lower->properties()['minecraft:cardinal_direction'], $upper->properties()['minecraft:cardinal_direction']);
        self::assertSame(0, $lower->properties()['door_hinge_bit']);
        self::assertSame(0, $lower->properties()['open_bit']);
    }

    public function testPlacementChoosesRightHingeBesideMatchingOrOpaqueLeftNeighbor(): void
    {
        $base = $this->doorState('minecraft:wooden_door', 0, 0, 0, 'south');
        $context = new BlockPlacementContext(1, 0.5, 0.5, 0.5, 90.0);

        $besideDoor = $this->states->state($this->resolver->place(
            $base,
            $context,
            true,
            false,
            false,
        )->lower);
        $besideWall = $this->states->state($this->resolver->place(
            $base,
            $context,
            false,
            true,
            false,
        )->lower);

        self::assertSame(1, $besideDoor->properties()['door_hinge_bit']);
        self::assertSame(1, $besideWall->properties()['door_hinge_bit']);
        self::assertSame('north', $besideDoor->properties()['minecraft:cardinal_direction']);
    }

    public function testDoorToggleSynchronizesBothHalves(): void
    {
        $closedLower = $this->doorState('minecraft:wooden_door', 0, 0, 0, 'west');
        $closedUpper = $this->doorState('minecraft:wooden_door', 1, 0, 0, 'west');

        $pair = $this->resolver->toggle($closedLower, $closedUpper);

        self::assertSame(1, $this->states->state($pair->lower)->properties()['open_bit']);
        self::assertSame(1, $this->states->state($pair->upper)->properties()['open_bit']);
        self::assertTrue($this->resolver->canPlayerToggle($closedLower));
        self::assertFalse($this->resolver->canPlayerToggle(
            $this->doorState('minecraft:iron_door', 0, 0, 0, 'west'),
        ));
    }

    public function testDoorToggleRejectsMismatchedHalves(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Door interaction requires matching lower and upper halves.');

        $this->resolver->toggle(
            $this->doorState('minecraft:wooden_door', 0, 0, 0, 'west'),
            $this->doorState('minecraft:iron_door', 1, 0, 0, 'west'),
        );
    }

    private function doorState(
        string $identifier,
        int $upper,
        int $hinge,
        int $open,
        string $direction,
    ): CanonicalBlockState {
        return CanonicalBlockState::from($identifier, [
            'door_hinge_bit' => $hinge,
            'minecraft:cardinal_direction' => $direction,
            'open_bit' => $open,
            'upper_block_bit' => $upper,
        ]);
    }
}
