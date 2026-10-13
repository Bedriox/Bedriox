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

namespace Bedriox\Server\World\Generation\Structure;

final readonly class StructureTransform
{
    public function __construct(
        public StructureRotation $rotation = StructureRotation::None,
        public StructureMirror $mirror = StructureMirror::None,
    ) {}

    public function coordinate(StructureCoordinate $coordinate, int $width, int $depth): StructureCoordinate
    {
        $x = match ($this->mirror) {
            StructureMirror::X => $width - 1 - $coordinate->x,
            default => $coordinate->x,
        };
        $z = match ($this->mirror) {
            StructureMirror::Z => $depth - 1 - $coordinate->z,
            default => $coordinate->z,
        };

        return match ($this->rotation) {
            StructureRotation::None => new StructureCoordinate($x, $coordinate->y, $z),
            StructureRotation::Clockwise90 => new StructureCoordinate($depth - 1 - $z, $coordinate->y, $x),
            StructureRotation::Clockwise180 => new StructureCoordinate($width - 1 - $x, $coordinate->y, $depth - 1 - $z),
            StructureRotation::Clockwise270 => new StructureCoordinate($z, $coordinate->y, $width - 1 - $x),
        };
    }

    public function direction(StructureDirection $direction): StructureDirection
    {
        $mirrored = match ($this->mirror) {
            StructureMirror::None => $direction,
            StructureMirror::X => match ($direction) {
                StructureDirection::East => StructureDirection::West,
                StructureDirection::West => StructureDirection::East,
                default => $direction,
            },
            StructureMirror::Z => match ($direction) {
                StructureDirection::North => StructureDirection::South,
                StructureDirection::South => StructureDirection::North,
                default => $direction,
            },
        };

        $index = match ($mirrored) {
            StructureDirection::North => 0,
            StructureDirection::East => 1,
            StructureDirection::South => 2,
            StructureDirection::West => 3,
        };

        return StructureDirection::cases()[($index + $this->rotation->value) % 4];
    }

    public function width(int $width, int $depth): int
    {
        return match ($this->rotation) {
            StructureRotation::Clockwise90, StructureRotation::Clockwise270 => $depth,
            default => $width,
        };
    }

    public function depth(int $width, int $depth): int
    {
        return match ($this->rotation) {
            StructureRotation::Clockwise90, StructureRotation::Clockwise270 => $width,
            default => $depth,
        };
    }
}
