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
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Pure farmland hydration, drying, support, and trampling rules. */
final class FarmlandBehaviorResolver
{
    public const MAXIMUM_MOISTURE = 7;

    private readonly InternalBlockStateId $dirt;

    /** @var array<string, InternalBlockStateId> */
    private readonly array $cropsByItem;

    /** @var array<int, InternalBlockStateId> */
    private readonly array $farmlandByMoisture;

    public function __construct(BlockStateRegistry $states)
    {
        $this->dirt = $states->internalId(CanonicalBlockState::from('minecraft:dirt'));
        $farmland = [];
        for ($moisture = 0; $moisture <= self::MAXIMUM_MOISTURE; ++$moisture) {
            $farmland[$moisture] = $states->internalId(CanonicalBlockState::from(
                'minecraft:farmland',
                ['moisturized_amount' => $moisture],
            ));
        }
        $this->farmlandByMoisture = $farmland;
        $this->cropsByItem = [
            'minecraft:wheat_seeds' => $states->internalId(CanonicalBlockState::from('minecraft:wheat', ['growth' => 0])),
            'minecraft:carrot' => $states->internalId(CanonicalBlockState::from('minecraft:carrots', ['growth' => 0])),
            'minecraft:potato' => $states->internalId(CanonicalBlockState::from('minecraft:potatoes', ['growth' => 0])),
            'minecraft:beetroot_seeds' => $states->internalId(CanonicalBlockState::from('minecraft:beetroot', ['growth' => 0])),
            'minecraft:torchflower_seeds' => $states->internalId(CanonicalBlockState::from('minecraft:torchflower_crop', ['growth' => 0])),
            'minecraft:pitcher_pod' => $states->internalId(CanonicalBlockState::from(
                'minecraft:pitcher_crop',
                ['growth' => 0, 'upper_block_bit' => 0],
            )),
        ];
    }

    public function resolveRandomTick(
        CanonicalBlockState $farmland,
        bool $hydrated,
        bool $supportsGrowingCrop,
    ): ?InternalBlockStateId {
        $moisture = $this->moisture($farmland);
        if ($hydrated) {
            return $moisture === self::MAXIMUM_MOISTURE ? null : $this->farmlandByMoisture[self::MAXIMUM_MOISTURE];
        }
        if ($moisture > 0) {
            return $this->farmlandByMoisture[$moisture - 1];
        }

        return $supportsGrowingCrop ? null : $this->dirt;
    }

    public function resolveCovered(CanonicalBlockState $farmland, bool $solidAbove): ?InternalBlockStateId
    {
        $this->moisture($farmland);

        return $solidAbove ? $this->dirt : null;
    }

    public function resolveTrample(CanonicalBlockState $farmland, float $fallDistance, float $randomUnit): ?InternalBlockStateId
    {
        $this->moisture($farmland);
        if (!is_finite($fallDistance) || $fallDistance < 0.0 || $fallDistance > 1_000_000.0) {
            throw new InvalidArgumentException('Farmland trample fall distance is outside the supported bounds.');
        }
        if (!is_finite($randomUnit) || $randomUnit < 0.0 || $randomUnit >= 1.0) {
            throw new InvalidArgumentException('Farmland trample random value must be in the range [0, 1).');
        }

        return $randomUnit < min(1.0, max(0.0, $fallDistance - 0.5)) ? $this->dirt : null;
    }

    public function supportsCrop(CanonicalBlockState|string $block): bool
    {
        $identifier = $block instanceof CanonicalBlockState ? $block->identifier() : $block;

        return in_array($identifier, [
            'minecraft:wheat',
            'minecraft:carrots',
            'minecraft:potatoes',
            'minecraft:beetroot',
            'minecraft:torchflower_crop',
            'minecraft:pitcher_crop',
        ], true);
    }

    /** Resolves the server-owned first growth stage for an item planted on farmland. */
    public function cropForItem(string $itemIdentifier): ?InternalBlockStateId
    {
        return $this->cropsByItem[$itemIdentifier] ?? null;
    }

    private function moisture(CanonicalBlockState $state): int
    {
        if ($state->identifier() !== 'minecraft:farmland') {
            throw new InvalidArgumentException('Farmland behavior requires a farmland block state.');
        }
        $moisture = $state->properties()['moisturized_amount'] ?? null;
        if (!is_int($moisture) || $moisture < 0 || $moisture > self::MAXIMUM_MOISTURE) {
            throw new InvalidArgumentException('Farmland block state has an invalid moisturized_amount.');
        }

        return $moisture;
    }
}
