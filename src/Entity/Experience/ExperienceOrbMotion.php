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

namespace Bedriox\Server\Entity\Experience;

use InvalidArgumentException;

/** Immutable experience-orb motion in blocks per simulation tick. */
final readonly class ExperienceOrbMotion
{
    public function __construct(public float $x = 0.0, public float $y = 0.0, public float $z = 0.0)
    {
        foreach ([$x, $y, $z] as $component) {
            if (!is_finite($component) || abs($component) > 100.0) {
                throw new InvalidArgumentException('Experience-orb motion must be finite and bounded.');
            }
        }
    }
}
