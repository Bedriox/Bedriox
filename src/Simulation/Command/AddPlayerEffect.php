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

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;

final readonly class AddPlayerEffect implements WorldCommand
{
    public function __construct(
        public string $session,
        public EffectInstance $effect,
        public EffectCause $cause,
        public float $intensity = 1.0,
    ) {
        if (!is_finite($intensity) || $intensity < 0.0 || $intensity > 1.0) {
            throw new \InvalidArgumentException('Effect intensity must be between zero and one.');
        }
    }

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 96 + strlen($this->session) + strlen($this->effect->type->value);
    }
}
