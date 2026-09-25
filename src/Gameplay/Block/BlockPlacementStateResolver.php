<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockAxis;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use LogicException;

/** Resolves the exact server-owned block state produced by a placement. */
final readonly class BlockPlacementStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    public function resolve(CanonicalBlockState $itemState, int $clickedFace, float $playerYaw = 0.0): InternalBlockStateId
    {
        $properties = $itemState->properties();
        if (array_key_exists('pillar_axis', $properties)) {
            $properties['pillar_axis'] = BlockAxis::fromBlockFace($clickedFace)->value;
        }
        if (array_key_exists('minecraft:cardinal_direction', $properties)) {
            $properties['minecraft:cardinal_direction'] = self::oppositeHorizontalDirection($playerYaw);
        }
        if (array_key_exists('facing_direction', $properties)
            && $itemState->identifier() === 'minecraft:barrel') {
            $properties['facing_direction'] = self::oppositeHorizontalFace($playerYaw);
        }
        if (array_key_exists('open_bit', $properties)) {
            $properties['open_bit'] = 0;
        }

        return $this->states->internalId(CanonicalBlockState::from($itemState->identifier(), $properties));
    }

    private static function oppositeHorizontalDirection(float $yaw): string
    {
        return match (self::oppositeHorizontalFace($yaw)) {
            2 => 'north',
            3 => 'south',
            4 => 'west',
            5 => 'east',
            default => throw new LogicException('Horizontal placement face is outside its supported range.'),
        };
    }

    /** Bedrock facing_direction: north=2, south=3, west=4, east=5. */
    private static function oppositeHorizontalFace(float $yaw): int
    {
        $normalized = fmod($yaw, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        return match (true) {
            $normalized < 45.0 || $normalized >= 315.0 => 2,
            $normalized < 135.0 => 5,
            $normalized < 225.0 => 3,
            default => 4,
        };
    }
}
