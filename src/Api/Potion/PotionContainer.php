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

namespace Bedriox\Api\Potion;

/** Vanilla item containers capable of carrying a potion variant. */
enum PotionContainer: string
{
    case DRINKABLE = 'minecraft:potion';
    case SPLASH = 'minecraft:splash_potion';
    case LINGERING = 'minecraft:lingering_potion';

    public function isThrowable(): bool
    {
        return $this !== self::DRINKABLE;
    }
}
