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

namespace Bedriox\Server\Entity\Mount;

use InvalidArgumentException;

/** Bedrock rider-camera metadata owned by an authoritative mount relationship. */
final readonly class MountViewConstraint
{
    public function __construct(
        public bool $locked,
        public float $lockDegrees,
        public bool $hasRotation,
        public float $rotationOffsetDegrees,
    ) {
        if (!is_finite($lockDegrees) || abs($lockDegrees) > 360.0
            || !is_finite($rotationOffsetDegrees) || abs($rotationOffsetDegrees) > 360.0) {
            throw new InvalidArgumentException('Mount view angles must be finite and bounded.');
        }
    }

    public static function standard(): self
    {
        return new self(false, 181.0, false, 0.0);
    }

    public static function boat(): self
    {
        return new self(true, 90.0, false, -90.0);
    }
}
