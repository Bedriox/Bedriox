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

namespace Bedriox\Server\Gameplay\Block\Environment;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Detects the four-sapling footprint which selects vanilla giant-spruce growth. */
final readonly class GiantSpruceGrowthResolver
{
    /**
     * @param array<string, mixed> $nearby States keyed by "x:z" relative to the triggered sapling.
     */
    public function origin(BlockPosition $trigger, array $nearby): ?BlockPosition
    {
        if (count($nearby) > 9) {
            throw new InvalidArgumentException('Giant-spruce footprint lookup exceeds its bounded 3x3 neighborhood.');
        }
        foreach ($nearby as $key => $state) {
            if (preg_match('/^-?[01]:-?[01]$/D', $key) !== 1 || !$state instanceof CanonicalBlockState) {
                throw new InvalidArgumentException('Giant-spruce footprint lookup contains an invalid entry.');
            }
        }

        foreach ([[0, 0], [-1, 0], [0, -1], [-1, -1]] as [$originX, $originZ]) {
            if ($this->isSpruce($nearby[$originX . ':' . $originZ] ?? null)
                && $this->isSpruce($nearby[($originX + 1) . ':' . $originZ] ?? null)
                && $this->isSpruce($nearby[$originX . ':' . ($originZ + 1)] ?? null)
                && $this->isSpruce($nearby[($originX + 1) . ':' . ($originZ + 1)] ?? null)) {
                return new BlockPosition($trigger->x + $originX, $trigger->y, $trigger->z + $originZ);
            }
        }

        return null;
    }

    private function isSpruce(mixed $state): bool
    {
        return $state instanceof CanonicalBlockState
            && $state->identifier() === 'minecraft:spruce_sapling';
    }
}
