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

use Bedriox\Data\CanonicalBlockState;

/** Transforms the bounded directional properties used by Bedriox-owned templates. */
final class StructureStateTransformer
{
    public static function transform(CanonicalBlockState $state, StructureTransform $transform): CanonicalBlockState
    {
        $properties = $state->properties();
        $cardinalKey = array_key_exists('minecraft:cardinal_direction', $properties)
            ? 'minecraft:cardinal_direction'
            : (array_key_exists('cardinal_direction', $properties) ? 'cardinal_direction' : null);
        if ($cardinalKey !== null && is_string($properties[$cardinalKey])) {
            $direction = self::directionFromName($properties[$cardinalKey]);
            if ($direction !== null) {
                $properties[$cardinalKey] = $transform->direction($direction)->value;
            }
        }

        if (isset($properties['facing_direction']) && is_int($properties['facing_direction'])) {
            $direction = match ($properties['facing_direction']) {
                2 => StructureDirection::North,
                3 => StructureDirection::South,
                4 => StructureDirection::West,
                5 => StructureDirection::East,
                default => null,
            };
            if ($direction !== null) {
                $properties['facing_direction'] = match ($transform->direction($direction)) {
                    StructureDirection::North => 2,
                    StructureDirection::South => 3,
                    StructureDirection::West => 4,
                    StructureDirection::East => 5,
                };
            }
        }

        if (isset($properties['pillar_axis']) && is_string($properties['pillar_axis'])
            && ($transform->rotation === StructureRotation::Clockwise90
                || $transform->rotation === StructureRotation::Clockwise270)) {
            $properties['pillar_axis'] = match ($properties['pillar_axis']) {
                'x' => 'z',
                'z' => 'x',
                default => $properties['pillar_axis'],
            };
        }

        return CanonicalBlockState::from($state->identifier(), $properties);
    }

    private static function directionFromName(string $name): ?StructureDirection
    {
        return match ($name) {
            'north' => StructureDirection::North,
            'east' => StructureDirection::East,
            'south' => StructureDirection::South,
            'west' => StructureDirection::West,
            default => null,
        };
    }
}
