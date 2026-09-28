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

namespace Bedriox\Api\Inventory;

/** Vanilla storage layouts which the Bedrock client can represent without specialized processing rules. */
enum ContainerLayout: string
{
    case SINGLE_CHEST = 'single_chest';
    case DOUBLE_CHEST = 'double_chest';
    case HOPPER = 'hopper';
    case DISPENSER = 'dispenser';
    case DROPPER = 'dropper';

    public function size(): int
    {
        return match ($this) {
            self::SINGLE_CHEST => 27,
            self::DOUBLE_CHEST => 54,
            self::HOPPER => 5,
            self::DISPENSER, self::DROPPER => 9,
        };
    }
}
