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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Data\RecipeStation;

enum CampfireType: string
{
    case Campfire = 'campfire';
    case SoulCampfire = 'soul_campfire';

    public function recipeStation(): RecipeStation
    {
        return $this === self::Campfire ? RecipeStation::CAMPFIRE : RecipeStation::SOUL_CAMPFIRE;
    }
}
