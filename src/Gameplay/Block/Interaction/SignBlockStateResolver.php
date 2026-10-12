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

namespace Bedriox\Server\Gameplay\Block\Interaction;

use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\BlockPlacementContext;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Resolves ordinary and hanging sign placement without trusting client runtime-state predictions. */
final readonly class SignBlockStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    public function place(CanonicalBlockState $itemState, BlockPlacementContext $context): InternalBlockStateId
    {
        $identifier = $itemState->identifier();
        if (BlockTagRegistry::contains(BlockTag::HANGING_SIGN, $identifier)) {
            return $this->placeHanging($itemState, $context);
        }
        if (!str_ends_with($identifier, '_standing_sign') && $identifier !== 'minecraft:standing_sign') {
            throw new InvalidArgumentException('Canonical block state is not a placeable sign item state.');
        }
        if ($context->clickedFace === 0) {
            throw new InvalidArgumentException('An ordinary sign cannot be attached beneath a block.');
        }

        if ($context->clickedFace === 1) {
            return $this->states->internalId(CanonicalBlockState::from($identifier, [
                'ground_sign_direction' => self::rotation($context->playerYaw),
            ]));
        }

        return $this->states->internalId(CanonicalBlockState::from(
            self::wallIdentifier($identifier),
            ['facing_direction' => $context->clickedFace],
        ));
    }

    private function placeHanging(CanonicalBlockState $state, BlockPlacementContext $context): InternalBlockStateId
    {
        if ($context->clickedFace === 1) {
            throw new InvalidArgumentException('A hanging sign cannot be placed on top of a block.');
        }
        if ($context->clickedFace === 0) {
            return $this->states->internalId(CanonicalBlockState::from($state->identifier(), [
                'attached_bit' => 1,
                'facing_direction' => 2,
                'ground_sign_direction' => self::rotation($context->playerYaw),
                'hanging' => 1,
            ]));
        }

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), [
            'attached_bit' => 0,
            'facing_direction' => self::wallHangingDirection($context->clickedFace, $context->playerYaw),
            'ground_sign_direction' => 0,
            'hanging' => 0,
        ]));
    }

    /**
     * Wall hanging signs use their board axis, not the supporting block face. Keep the sign perpendicular
     * to its support and turn its front away from the player's facing when both would otherwise match.
     */
    private static function wallHangingDirection(int $clickedFace, float $playerYaw): int
    {
        $direction = match ($clickedFace) {
            2 => 5,
            3 => 4,
            4 => 2,
            5 => 3,
            default => throw new InvalidArgumentException('A wall hanging sign requires a horizontal support face.'),
        };

        return $direction === self::horizontalFacing($playerYaw)
            ? self::oppositeFace($direction)
            : $direction;
    }

    private static function horizontalFacing(float $yaw): int
    {
        $normalized = fmod($yaw, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        return match (true) {
            $normalized < 45.0 || $normalized >= 315.0 => 3,
            $normalized < 135.0 => 4,
            $normalized < 225.0 => 2,
            default => 5,
        };
    }

    private static function oppositeFace(int $face): int
    {
        return match ($face) {
            2 => 3,
            3 => 2,
            4 => 5,
            5 => 4,
            default => throw new InvalidArgumentException('Only horizontal faces have a wall-sign opposite.'),
        };
    }

    private static function wallIdentifier(string $standingIdentifier): string
    {
        return str_ends_with($standingIdentifier, '_standing_sign')
            ? substr($standingIdentifier, 0, -strlen('_standing_sign')) . '_wall_sign'
            : 'minecraft:wall_sign';
    }

    /** Bedrock standing-sign rotation has sixteen clockwise steps of 22.5 degrees. */
    private static function rotation(float $yaw): int
    {
        $normalized = fmod($yaw, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        return ((int) floor(($normalized + 180.0) / 22.5 + 0.5)) & 0x0f;
    }
}
