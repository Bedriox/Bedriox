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

namespace Bedriox\Api\World\Particle;

final readonly class SimpleParticle implements Particle
{
    public function __construct(
        private ParticleType $particleType,
        private ?ParticleVariables $particleVariables = null,
    ) {}

    public function type(): ParticleType
    {
        return $this->particleType;
    }

    public function variables(): ?ParticleVariables
    {
        return $this->particleVariables;
    }

    public function estimatedBytes(): int
    {
        return 32 + strlen($this->particleType->value)
            + ($this->particleVariables === null ? 0 : strlen($this->particleVariables->toJson()));
    }
}
