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

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Resolves and validates the two authoritative halves of a vanilla bed. */
final readonly class BedBlockStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    /** @return array{foot: InternalBlockStateId, head: InternalBlockStateId, headPosition: BlockPosition} */
    public function placement(BlockPosition $footPosition, float $playerYaw): array
    {
        $direction = BedDirection::fromYaw($playerYaw);
        [$dx, $dz] = $direction->offset();
        $properties = ['direction' => $direction->value, 'head_piece_bit' => 0, 'occupied_bit' => 0];

        return [
            'foot' => $this->states->internalId(CanonicalBlockState::from('minecraft:bed', $properties)),
            'head' => $this->states->internalId(CanonicalBlockState::from(
                'minecraft:bed',
                array_replace($properties, ['head_piece_bit' => 1]),
            )),
            'headPosition' => new BlockPosition($footPosition->x + $dx, $footPosition->y, $footPosition->z + $dz),
        ];
    }

    public function otherHalf(BlockPosition $position, CanonicalBlockState $state): BlockPosition
    {
        self::assertBed($state);
        $direction = $state->properties()['direction'];
        if (!is_int($direction)) {
            throw new InvalidArgumentException('Bed direction must be an integer.');
        }
        $bedDirection = BedDirection::tryFrom($direction)
            ?? throw new InvalidArgumentException('Bed direction is outside its supported range.');
        [$dx, $dz] = $bedDirection->offset();
        $head = $state->properties()['head_piece_bit'] === 1;

        return new BlockPosition($position->x + ($head ? -$dx : $dx), $position->y, $position->z + ($head ? -$dz : $dz));
    }

    public function withOccupied(CanonicalBlockState $state, bool $occupied): InternalBlockStateId
    {
        self::assertBed($state);
        $properties = $state->properties();
        $properties['occupied_bit'] = $occupied ? 1 : 0;

        return $this->states->internalId(CanonicalBlockState::from('minecraft:bed', $properties));
    }

    public function isMatchingHalf(CanonicalBlockState $first, CanonicalBlockState $second): bool
    {
        if ($first->identifier() !== 'minecraft:bed' || $second->identifier() !== 'minecraft:bed') {
            return false;
        }
        $left = $first->properties();
        $right = $second->properties();

        return ($left['direction'] ?? null) === ($right['direction'] ?? null)
            && ($left['head_piece_bit'] ?? null) !== ($right['head_piece_bit'] ?? null);
    }

    private static function assertBed(CanonicalBlockState $state): void
    {
        $properties = $state->properties();
        if ($state->identifier() !== 'minecraft:bed'
            || !isset($properties['direction'], $properties['head_piece_bit'], $properties['occupied_bit'])) {
            throw new InvalidArgumentException('Canonical block state is not a complete bed state.');
        }
    }

}
