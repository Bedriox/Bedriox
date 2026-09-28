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

namespace Bedriox\Api\World;

/** Named overworld daylight-cycle positions accepted by the time command and plugin APIs. */
enum WorldTimePreset: string
{
    case DAY = 'day';
    case NOON = 'noon';
    case SUNSET = 'sunset';
    case NIGHT = 'night';
    case MIDNIGHT = 'midnight';
    case SUNRISE = 'sunrise';

    public function ticks(): int
    {
        return match ($this) {
            self::DAY => 1_000,
            self::NOON => 6_000,
            self::SUNSET => 12_000,
            self::NIGHT => 13_000,
            self::MIDNIGHT => 18_000,
            self::SUNRISE => 23_000,
        };
    }
}
