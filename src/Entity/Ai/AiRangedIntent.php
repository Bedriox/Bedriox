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

namespace Bedriox\Server\Entity\Ai;

use InvalidArgumentException;

/** Bounded advisory intent; authoritative simulation revalidates target, sight, range, and equipment. */
final readonly class AiRangedIntent
{
    public function __construct(
        public string $targetPlayerId,
        public int $createdAtTick,
        public float $maximumRange,
        public float $projectileSpeed,
    ) {
        if ($targetPlayerId === '' || strlen($targetPlayerId) > 128 || preg_match('//u', $targetPlayerId) !== 1) {
            throw new InvalidArgumentException('AI ranged target identity must be valid UTF-8 and bounded.');
        }
        if ($createdAtTick < 0 || !is_finite($maximumRange) || $maximumRange <= 0.0 || $maximumRange > 128.0
            || !is_finite($projectileSpeed) || $projectileSpeed < 0.1 || $projectileSpeed > 3.2) {
            throw new InvalidArgumentException('AI ranged intent is outside its supported bounds.');
        }
    }
}
