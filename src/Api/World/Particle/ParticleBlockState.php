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

use Bedriox\Data\CanonicalBlockState;

final readonly class ParticleBlockState
{
    private CanonicalBlockState $state;

    /** @param array<array-key, mixed> $properties */
    public function __construct(string $identifier, array $properties = [])
    {
        $this->state = CanonicalBlockState::from($identifier, $properties);
    }

    public function identifier(): string
    {
        return $this->state->identifier();
    }

    /** @return array<string, int|string> */
    public function properties(): array
    {
        return $this->state->properties();
    }

    public function canonicalKey(): string
    {
        return $this->state->canonicalKey();
    }
}
