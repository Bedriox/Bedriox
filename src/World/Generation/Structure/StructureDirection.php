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

enum StructureDirection: string
{
    case North = 'north';
    case East = 'east';
    case South = 'south';
    case West = 'west';

    public function opposite(): self
    {
        return match ($this) {
            self::North => self::South,
            self::East => self::West,
            self::South => self::North,
            self::West => self::East,
        };
    }

    public function offsetX(): int
    {
        return match ($this) {
            self::East => 1,
            self::West => -1,
            default => 0,
        };
    }

    public function offsetZ(): int
    {
        return match ($this) {
            self::South => 1,
            self::North => -1,
            default => 0,
        };
    }
}
