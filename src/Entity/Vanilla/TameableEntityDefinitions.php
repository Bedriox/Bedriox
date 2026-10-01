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

final class TameableEntityDefinitions
{
    private static ?EntityDefinition $wolf = null;
    private static ?EntityDefinition $cat = null;

    public static function wolf(): EntityDefinition
    {
        return self::$wolf ??= new EntityDefinition(
            VanillaEntityType::WOLF,
            EntityCategory::ANIMAL,
            VanillaEntityType::WOLF->value,
            0.6,
            0.85,
            40.0,
        );
    }

    public static function cat(): EntityDefinition
    {
        return self::$cat ??= new EntityDefinition(
            VanillaEntityType::CAT,
            EntityCategory::ANIMAL,
            VanillaEntityType::CAT->value,
            0.6,
            0.7,
            10.0,
        );
    }
}
