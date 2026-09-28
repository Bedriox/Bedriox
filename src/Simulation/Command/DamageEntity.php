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

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Api\Entity\EntityDamageCause;

/** Bounded authoritative damage request for a non-player living entity. */
final readonly class DamageEntity implements WorldCommand
{
    public function __construct(
        public string $source,
        public int $runtimeId,
        public string $uniqueId,
        public float $amount,
        public EntityDamageCause $cause,
    ) {}

    public function sessionId(): string
    {
        return $this->source;
    }

    public function estimatedBytes(): int
    {
        return 80 + strlen($this->source) + strlen($this->uniqueId);
    }
}
