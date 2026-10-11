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

use Bedriox\Api\Entity\Value\SkinAnimationExpression;
use Bedriox\Api\Entity\Value\SkinAnimationType;
use InvalidArgumentException;

/** Validated animation data embedded in a Skin, not a command to animate an actor. */
final readonly class SkinAnimation
{
    public function __construct(
        public int $width,
        public int $height,
        public string $rgba,
        public SkinAnimationType $type,
        public float $frames,
        public SkinAnimationExpression $expression,
    ) {
        if ($width < 1 || $height < 1 || $width > 256 || $height > 256
            || strlen($rgba) !== $width * $height * 4) {
            throw new InvalidArgumentException('Skin animation dimensions must match bounded RGBA data.');
        }
        if (!is_finite($frames) || $frames < 0.0 || $frames > 1_000_000.0) {
            throw new InvalidArgumentException('Skin animation metadata is outside its supported bounds.');
        }
    }
}
