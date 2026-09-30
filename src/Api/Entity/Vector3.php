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

namespace Bedriox\Api\Entity;

use InvalidArgumentException;

/** Immutable finite motion or direction vector exposed to plugins. */
final readonly class Vector3
{
    public function __construct(public float $x, public float $y, public float $z)
    {
        if (!is_finite($x) || !is_finite($y) || !is_finite($z)
            || abs($x) > 1_000.0 || abs($y) > 1_000.0 || abs($z) > 1_000.0) {
            throw new InvalidArgumentException('Vector components must be finite and bounded.');
        }
    }
}
