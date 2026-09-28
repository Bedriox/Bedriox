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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final readonly class NaturalDespawnState
{
    public function __construct(
        public string $worldName,
        public EntityCategory $category,
        public Position $position,
        public int $ageTicks,
        public bool $named = false,
        public bool $tamed = false,
        public bool $leashed = false,
        public bool $ridden = false,
        public bool $pluginForced = false,
        public bool $persistent = false,
    ) {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1
            || !is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0 || $ageTicks < 0 || $ageTicks > 0x7fffffff) {
            throw new InvalidArgumentException('Natural-despawn state is invalid.');
        }
    }

    public function exempt(): bool
    {
        return $this->named || $this->tamed || $this->leashed || $this->ridden
            || $this->pluginForced || $this->persistent;
    }
}
