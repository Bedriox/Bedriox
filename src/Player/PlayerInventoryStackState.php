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

namespace Bedriox\Server\Player;

use Bedriox\Api\Inventory\ItemNbt;
use InvalidArgumentException;

/** Canonical inventory content which is safe to carry across play sessions. */
final readonly class PlayerInventoryStackState
{
    public const int MAX_DAMAGE = 65_535;
    public const int MAX_AUX_VALUE = 32_767;

    public function __construct(
        public string $identifier,
        public int $count,
        public int $damage = 0,
        public ?ItemNbt $nbt = null,
        public int $auxValue = 0,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $this->identifier) !== 1) {
            throw new InvalidArgumentException('Inventory identifier must be canonical and namespaced.');
        }
        if ($this->count < 1 || $this->count > 64) {
            throw new InvalidArgumentException('Inventory stack count must be between 1 and 64.');
        }
        if ($this->damage < 0 || $this->damage > self::MAX_DAMAGE) {
            throw new InvalidArgumentException('Inventory stack damage is outside its supported range.');
        }
        if ($this->auxValue < 0 || $this->auxValue > self::MAX_AUX_VALUE) {
            throw new InvalidArgumentException('Inventory stack aux value is outside its supported range.');
        }
    }
}
