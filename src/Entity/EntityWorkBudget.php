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

use InvalidArgumentException;

/** Bounds expensive entity physics while safety-critical motion remains continuous. */
final readonly class EntityWorkBudget
{
    public function __construct(
        public int $maximumPhysicsEntities = 512,
        public int $maximumNanoseconds = 4_000_000,
        public int $maximumContactCandidates = 16,
        public int $maximumContactPairs = 1_024,
        public int $maximumContactNanoseconds = 1_000_000,
    ) {
        if ($maximumPhysicsEntities < 1 || $maximumPhysicsEntities > 65_536
            || $maximumNanoseconds < 100_000 || $maximumNanoseconds > 50_000_000
            || $maximumContactCandidates < 2 || $maximumContactCandidates > 256
            || $maximumContactPairs < 1 || $maximumContactPairs > 65_536
            || $maximumContactNanoseconds < 100_000 || $maximumContactNanoseconds > 20_000_000) {
            throw new InvalidArgumentException('Entity work budget is outside its supported bounds.');
        }
    }
}
