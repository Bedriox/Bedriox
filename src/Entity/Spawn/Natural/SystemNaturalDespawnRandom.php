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

namespace Bedriox\Server\Entity\Spawn\Natural;

use InvalidArgumentException;

final readonly class SystemNaturalDespawnRandom implements NaturalDespawnRandom
{
    public function oneIn(int $chance): bool
    {
        if ($chance < 1 || $chance > 1_000_000) {
            throw new InvalidArgumentException('Natural-despawn chance is outside its supported bounds.');
        }

        return random_int(1, $chance) === 1;
    }
}
