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

namespace Bedriox\Server\Gameplay\Explosion\Value;

use InvalidArgumentException;

final readonly class ExplosionBlockSample
{
    public function __construct(
        public string $identifier,
        public float $blastResistance,
        public bool $air = false,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Explosion block identifier must be canonical.');
        }
        if (!is_finite($blastResistance) || $blastResistance < 0.0 || $blastResistance > 1_000_000.0) {
            throw new InvalidArgumentException('Explosion block resistance must be finite and bounded.');
        }
        if ($air && $blastResistance !== 0.0) {
            throw new InvalidArgumentException('Explosion air samples must have zero blast resistance.');
        }
    }

    public static function air(): self
    {
        return new self('minecraft:air', 0.0, true);
    }
}
