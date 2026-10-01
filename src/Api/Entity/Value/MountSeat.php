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

namespace Bedriox\Api\Entity\Value;

/** Stable seat identities supported by the authoritative mounting API. */
enum MountSeat: int
{
    case DRIVER = 0;
    case PASSENGER_1 = 1;
    case PASSENGER_2 = 2;
    case PASSENGER_3 = 3;

    public function controlsVehicle(): bool
    {
        return $this === self::DRIVER;
    }
}
