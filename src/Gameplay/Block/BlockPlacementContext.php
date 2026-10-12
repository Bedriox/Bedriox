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

namespace Bedriox\Server\Gameplay\Block;

use InvalidArgumentException;

/** Complete, bounded client placement intent needed to derive an authoritative block state. */
final readonly class BlockPlacementContext
{
    public function __construct(
        public int $clickedFace,
        public float $clickX,
        public float $clickY,
        public float $clickZ,
        public float $playerYaw,
    ) {
        if ($clickedFace < 0 || $clickedFace > 5) {
            throw new InvalidArgumentException('Block placement face must be between 0 and 5.');
        }
        foreach ([$clickX, $clickY, $clickZ] as $coordinate) {
            if (!is_finite($coordinate) || $coordinate < 0.0 || $coordinate > 1.0) {
                throw new InvalidArgumentException('Block placement hit coordinates must be finite values between 0 and 1.');
            }
        }
        if (!is_finite($playerYaw)) {
            throw new InvalidArgumentException('Block placement yaw must be finite.');
        }
    }
}
