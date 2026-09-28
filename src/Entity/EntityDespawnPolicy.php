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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\SpawnCause;

/** Durable ownership of automatic entity removal. */
enum EntityDespawnPolicy: string
{
    case EXPLICIT_ONLY = 'explicit_only';
    case NATURAL_DISTANCE = 'natural_distance';

    public static function forSpawnCause(SpawnCause $cause): self
    {
        return $cause === SpawnCause::NATURAL ? self::NATURAL_DISTANCE : self::EXPLICIT_ONLY;
    }
}
