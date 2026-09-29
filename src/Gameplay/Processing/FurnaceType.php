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
use Bedriox\Server\World\BlockEntity\BlockEntityType;

enum FurnaceType: string
{
    case Furnace = 'furnace';
    case BlastFurnace = 'blast_furnace';
    case Smoker = 'smoker';

    public function recipeStation(): RecipeStation
    {
        return match ($this) {
            self::Furnace => RecipeStation::FURNACE,
            self::BlastFurnace => RecipeStation::BLAST_FURNACE,
            self::Smoker => RecipeStation::SMOKER,
        };
    }

    public function blockEntityType(): BlockEntityType
    {
        return match ($this) {
            self::Furnace => BlockEntityType::Furnace,
            self::BlastFurnace => BlockEntityType::BlastFurnace,
            self::Smoker => BlockEntityType::Smoker,
        };
    }

    public function cookTimeTicks(): int
    {
        return $this === self::Furnace ? 200 : 100;
    }
}
