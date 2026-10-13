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

namespace Bedriox\Server\Tests\World\Generation\Structure;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Generation\Structure\PlacedStructureConnector;
use Bedriox\Server\World\Generation\Structure\StructureConnector;
use Bedriox\Server\World\Generation\Structure\StructureCoordinate;
use Bedriox\Server\World\Generation\Structure\StructureDirection;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;
use Bedriox\Server\World\Generation\Structure\StructureMirror;
use Bedriox\Server\World\Generation\Structure\StructureRotation;
use Bedriox\Server\World\Generation\Structure\StructureTemplate;
use Bedriox\Server\World\Generation\Structure\StructureTemplateBlock;
use Bedriox\Server\World\Generation\Structure\StructureTemplateMarker;
use Bedriox\Server\World\Generation\Structure\StructureTransform;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StructureTemplateTest extends TestCase
{
    public function testAsymmetricOriginalFixtureRotatesAndMirrorsDeterministically(): void
    {
        $template = self::fixture();
        $transform = new StructureTransform(StructureRotation::Clockwise90, StructureMirror::X);

        $placement = $template->place(new StructureCoordinate(10, 64, -5), $transform);

        self::assertSame(2, $placement->width);
        self::assertSame(2, $placement->height);
        self::assertSame(3, $placement->depth);
        self::assertSame(
            [[11, 64, -3], [10, 64, -5], [11, 65, -5]],
            array_map(
                static fn($block): array => [$block->coordinate->x, $block->coordinate->y, $block->coordinate->z],
                $placement->blocks,
            ),
        );
        self::assertSame('minecraft:stone', $placement->blocks[0]->state->identifier());
        self::assertSame(StructureDirection::South, $placement->connector('west_door')->direction);
        self::assertSame([11, 64, -3], [
            $placement->markers[0]->coordinate->x,
            $placement->markers[0]->coordinate->y,
            $placement->markers[0]->coordinate->z,
        ]);
        self::assertSame(StructureDirection::East, $placement->markers[0]->direction);
        self::assertSame(['profession' => 'farmer'], $placement->markers[0]->attributes);
    }

    public function testCompatibleConnectorsAlignAdjacentAndFaceEachOther(): void
    {
        $target = new PlacedStructureConnector(
            'existing_door',
            'walkway',
            new StructureCoordinate(20, 70, 30),
            StructureDirection::East,
        );

        $placement = self::fixture()->connect(
            'west_door',
            $target,
            new StructureTransform(StructureRotation::None),
        );
        $connected = $placement->connector('west_door');

        self::assertEquals(new StructureCoordinate(21, 70, 30), $connected->coordinate);
        self::assertSame(StructureDirection::West, $connected->direction);
    }

    public function testConnectorKindAndFacingMustBeCompatible(): void
    {
        $template = self::fixture();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matching kinds');
        $template->connect('west_door', new PlacedStructureConnector(
            'target',
            'ladder',
            new StructureCoordinate(0, 0, 0),
            StructureDirection::East,
        ));
    }

    public function testConnectorFacingMustPointBackTowardTarget(): void
    {
        $template = self::fixture();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('directions do not face each other');
        $template->connect('west_door', new PlacedStructureConnector(
            'target',
            'walkway',
            new StructureCoordinate(0, 0, 0),
            StructureDirection::West,
        ));
    }

    public function testDirectionalBlockPropertiesFollowTemplateTransform(): void
    {
        $template = new StructureTemplate(2, 1, 1, [
            new StructureTemplateBlock(
                new StructureCoordinate(0, 0, 0),
                CanonicalBlockState::from('minecraft:test', [
                    'minecraft:cardinal_direction' => 'north',
                    'facing_direction' => 5,
                    'pillar_axis' => 'x',
                ]),
            ),
        ]);

        $state = $template->place(
            new StructureCoordinate(0, 0, 0),
            new StructureTransform(StructureRotation::Clockwise90, StructureMirror::X),
        )->blocks[0]->state;

        self::assertSame([
            'facing_direction' => 2,
            'minecraft:cardinal_direction' => 'east',
            'pillar_axis' => 'z',
        ], $state->properties());
    }

    public function testTemplateRejectsDuplicateBlockCoordinates(): void
    {
        $coordinate = new StructureCoordinate(0, 0, 0);
        $state = CanonicalBlockState::from('minecraft:stone');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate block coordinate');
        new StructureTemplate(1, 1, 1, [
            new StructureTemplateBlock($coordinate, $state),
            new StructureTemplateBlock($coordinate, $state),
        ]);
    }

    private static function fixture(): StructureTemplate
    {
        return new StructureTemplate(3, 2, 2, [
            new StructureTemplateBlock(new StructureCoordinate(0, 0, 0), CanonicalBlockState::from('minecraft:stone')),
            new StructureTemplateBlock(new StructureCoordinate(2, 0, 1), CanonicalBlockState::from('minecraft:oak_planks')),
            new StructureTemplateBlock(new StructureCoordinate(2, 1, 0), CanonicalBlockState::from('minecraft:lantern')),
        ], [
            new StructureConnector('west_door', 'walkway', new StructureCoordinate(0, 0, 0), StructureDirection::West),
        ], [
            new StructureTemplateMarker(
                'resident',
                StructureMarkerType::EntitySpawn,
                'minecraft:villager_v2',
                new StructureCoordinate(0, 0, 0),
                StructureDirection::North,
                ['profession' => 'farmer'],
            ),
        ]);
    }
}
