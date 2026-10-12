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

use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Processing\CauldronState;
use InvalidArgumentException;

/** Resolves one bounded stalactite-to-cauldron drip without reading or mutating a world. */
final readonly class DripstoneCauldronBehaviorResolver
{
    public const int MAXIMUM_COLUMN_BLOCKS = 12;

    /**
     * Column order is immediately above the cauldron moving upward.
     *
     * @param list<mixed> $column
     */
    public function source(array $column): ?CauldronContentType
    {
        if (count($column) > self::MAXIMUM_COLUMN_BLOCKS) {
            throw new InvalidArgumentException('Dripstone cauldron column exceeds its bounded scan height.');
        }
        foreach ($column as $state) {
            if (!$state instanceof CanonicalBlockState) {
                throw new InvalidArgumentException('Dripstone cauldron column must contain canonical block states.');
            }
        }

        $tipFound = false;
        $rootFound = false;
        foreach ($column as $state) {
            $identifier = $state->identifier();
            if (!$tipFound) {
                if ($identifier === 'minecraft:air') {
                    continue;
                }
                if (!$this->isHangingDripstone($state)
                    || ($state->properties()['dripstone_thickness'] ?? null) !== 'tip') {
                    return null;
                }
                $tipFound = true;
                continue;
            }
            if (!$rootFound && $this->isHangingDripstone($state)) {
                continue;
            }
            if (!$rootFound) {
                if ($identifier !== 'minecraft:dripstone_block') {
                    return null;
                }
                $rootFound = true;
                continue;
            }

            return $this->sourceFluid($state);
        }

        return null;
    }

    public function resolveFill(CauldronState $state, CauldronContentType $source): ?CauldronState
    {
        return match ($source) {
            CauldronContentType::WATER => match ($state->content) {
                CauldronContentType::EMPTY => new CauldronState(CauldronContentType::WATER, 1),
                CauldronContentType::WATER => $state->level < 6
                    ? new CauldronState(CauldronContentType::WATER, $state->level + 1)
                    : null,
                default => null,
            },
            CauldronContentType::LAVA => $state->content === CauldronContentType::EMPTY
                ? new CauldronState(CauldronContentType::LAVA, 6)
                : null,
            default => throw new InvalidArgumentException('Only water and lava may drip through pointed dripstone.'),
        };
    }

    private function isHangingDripstone(CanonicalBlockState $state): bool
    {
        return $state->identifier() === 'minecraft:pointed_dripstone'
            && ($state->properties()['hanging'] ?? null) === 1;
    }

    private function sourceFluid(CanonicalBlockState $state): ?CauldronContentType
    {
        if (($state->properties()['liquid_depth'] ?? null) !== 0) {
            return null;
        }

        return match ($state->identifier()) {
            'minecraft:water', 'minecraft:flowing_water' => CauldronContentType::WATER,
            'minecraft:lava', 'minecraft:flowing_lava' => CauldronContentType::LAVA,
            default => null,
        };
    }
}
