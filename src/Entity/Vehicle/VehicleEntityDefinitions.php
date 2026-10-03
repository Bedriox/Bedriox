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

namespace Bedriox\Server\Entity\Vehicle;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityDefinition;

final class VehicleEntityDefinitions
{
    private static ?EntityDefinition $boat = null;
    private static ?EntityDefinition $chestBoat = null;

    public static function boat(): EntityDefinition
    {
        return self::$boat ??= self::create(VanillaEntityType::BOAT);
    }

    public static function chestBoat(): EntityDefinition
    {
        return self::$chestBoat ??= self::create(VanillaEntityType::CHEST_BOAT);
    }

    private static function create(VanillaEntityType $type): EntityDefinition
    {
        return new EntityDefinition(
            $type,
            EntityCategory::MISCELLANEOUS,
            $type->value,
            1.4,
            0.455,
            40.0,
            gravity: 0.04,
            drag: 0.10,
        );
    }
}
