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

namespace Bedriox\Server\Entity\Mount;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityDefinition;

final class MountEntityDefinitions
{
    /** @var array<string, EntityDefinition> */ private static array $definitions = [];
    public static function horse(): EntityDefinition
    {
        return self::get(VanillaEntityType::HORSE, 1.3965, 1.6, 15.0);
    }
    public static function donkey(): EntityDefinition
    {
        return self::get(VanillaEntityType::DONKEY, 1.3965, 1.6, 15.0);
    }
    public static function mule(): EntityDefinition
    {
        return self::get(VanillaEntityType::MULE, 1.3965, 1.6, 15.0);
    }
    public static function camel(): EntityDefinition
    {
        return self::get(VanillaEntityType::CAMEL, 1.7, 2.375, 32.0);
    }
    public static function llama(): EntityDefinition
    {
        return self::get(VanillaEntityType::LLAMA, 0.9, 1.87, 15.0);
    }
    public static function traderLlama(): EntityDefinition
    {
        return self::get(VanillaEntityType::TRADER_LLAMA, 0.9, 1.87, 15.0);
    }
    public static function skeletonHorse(): EntityDefinition
    {
        return self::get(VanillaEntityType::SKELETON_HORSE, 1.4, 1.6, 15.0);
    }
    public static function zombieHorse(): EntityDefinition
    {
        return self::get(VanillaEntityType::ZOMBIE_HORSE, 1.4, 1.6, 15.0);
    }
    private static function get(VanillaEntityType $type, float $width, float $height, float $health): EntityDefinition
    {
        return self::$definitions[$type->value] ??= new EntityDefinition($type, EntityCategory::ANIMAL, $type->value, $width, $height, $health);
    }
}
