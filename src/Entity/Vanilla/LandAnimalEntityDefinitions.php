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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityDefinition;

/** Immutable definitions for the expanded land-animal registry. */
final class LandAnimalEntityDefinitions
{
    /** @var array<string, EntityDefinition> */
    private static array $definitions = [];

    public static function fox(): EntityDefinition
    {
        return self::get(VanillaEntityType::FOX, 0.6, 0.7, 10.0);
    }

    public static function goat(): EntityDefinition
    {
        return self::get(VanillaEntityType::GOAT, 0.63, 0.91, 10.0);
    }

    public static function panda(): EntityDefinition
    {
        return self::get(VanillaEntityType::PANDA, 1.125, 1.25, 20.0);
    }

    public static function polarBear(): EntityDefinition
    {
        return self::get(VanillaEntityType::POLAR_BEAR, 1.3, 1.4, 30.0);
    }

    public static function armadillo(): EntityDefinition
    {
        return self::get(VanillaEntityType::ARMADILLO, 0.7, 0.65, 12.0);
    }

    public static function mooshroom(): EntityDefinition
    {
        return self::get(VanillaEntityType::MOOSHROOM, 0.9, 1.4, 10.0);
    }

    public static function sniffer(): EntityDefinition
    {
        return self::get(VanillaEntityType::SNIFFER, 1.9, 1.75, 14.0);
    }

    public static function ocelot(): EntityDefinition
    {
        return self::get(VanillaEntityType::OCELOT, 0.6, 0.7, 10.0);
    }

    private static function get(VanillaEntityType $type, float $width, float $height, float $health): EntityDefinition
    {
        return self::$definitions[$type->value] ??= new EntityDefinition(
            $type,
            EntityCategory::ANIMAL,
            $type->value,
            $width,
            $height,
            $health,
        );
    }
}
