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

final readonly class BoggedNaturalSpawnRule implements NaturalSpawnRule
{
    public function allows(NaturalSpawnContext $context): bool
    {
        return $context->medium === NaturalSpawnMedium::GROUND
            && $context->lightLevel <= 7
            && (str_contains($context->biome, 'swamp') || str_contains($context->biome, 'mangrove'));
    }
}
