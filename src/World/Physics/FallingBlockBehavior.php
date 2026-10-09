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

namespace Bedriox\Server\World\Physics;

use InvalidArgumentException;

final readonly class FallingBlockBehavior
{
    public function __construct(
        public FallingBlockKind $kind = FallingBlockKind::ORDINARY,
        public ?string $waterHardenedIdentifier = null,
        public float $damagePerBlock = 0.0,
        public float $maximumDamage = 0.0,
    ) {
        if ($waterHardenedIdentifier !== null
            && preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $waterHardenedIdentifier) !== 1) {
            throw new InvalidArgumentException('A falling-block hardened identifier must be canonical.');
        }
        if (!is_finite($damagePerBlock) || $damagePerBlock < 0.0 || $damagePerBlock > 1_000.0
            || !is_finite($maximumDamage) || $maximumDamage < 0.0 || $maximumDamage > 1_000_000.0) {
            throw new InvalidArgumentException('Falling-block damage values are outside their supported bounds.');
        }
        if ($kind === FallingBlockKind::CONCRETE_POWDER && $waterHardenedIdentifier === null) {
            throw new InvalidArgumentException('Concrete powder must define its hardened block identifier.');
        }
        if ($kind !== FallingBlockKind::CONCRETE_POWDER && $waterHardenedIdentifier !== null) {
            throw new InvalidArgumentException('Only concrete powder may define a hardened block identifier.');
        }
    }
}
