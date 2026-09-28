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

/** Bounded advisory intent; authoritative simulation must revalidate the target and reach. */
final readonly class AiMeleeIntent
{
    public function __construct(
        public string $targetPlayerId,
        public int $createdAtTick,
        public float $maximumReach,
        public float $damage,
    ) {
        if ($targetPlayerId === '' || strlen($targetPlayerId) > 128 || preg_match('//u', $targetPlayerId) !== 1) {
            throw new InvalidArgumentException('AI melee target identity must be valid UTF-8 and bounded.');
        }
        if ($createdAtTick < 0 || !is_finite($maximumReach) || $maximumReach <= 0.0 || $maximumReach > 16.0
            || !is_finite($damage) || $damage <= 0.0 || $damage > 1_000.0) {
            throw new InvalidArgumentException('AI melee intent is outside its supported bounds.');
        }
    }
}
