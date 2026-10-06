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

namespace Bedriox\Server\Gameplay\End;

enum EnderDragonPart: string
{
    case HEAD = 'head';
    case BODY = 'body';
    case WING = 'wing';
    case TAIL = 'tail';

    public function damageMultiplier(): float
    {
        return match ($this) {
            self::HEAD => 1.0,
            self::BODY => 0.5,
            self::WING, self::TAIL => 0.25,
        };
    }
}
