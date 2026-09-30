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

/** Overworld surface admission for sheep herds. */
final readonly class SheepNaturalSpawnRule implements NaturalSpawnRule
{
    public function allows(NaturalSpawnContext $context): bool
    {
        if ($context->medium !== NaturalSpawnMedium::GROUND || $context->lightLevel < 9) {
            return false;
        }
        foreach (['ocean', 'river', 'beach', 'desert', 'badlands', 'peak', 'slope'] as $excluded) {
            if (str_contains($context->biome, $excluded)) {
                return false;
            }
        }

        return true;
    }
}
