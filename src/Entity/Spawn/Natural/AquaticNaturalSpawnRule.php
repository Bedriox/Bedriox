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

final readonly class AquaticNaturalSpawnRule implements NaturalSpawnRule
{
    public function __construct(private int $maximumLight = 15) {}

    public function allows(NaturalSpawnContext $context): bool
    {
        return $context->dimension === 'minecraft:overworld'
            && $context->medium === NaturalSpawnMedium::WATER
            && $context->lightLevel <= $this->maximumLight;
    }
}
