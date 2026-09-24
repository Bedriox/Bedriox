<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockAxis;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;

/** Resolves the exact server-owned block state produced by a placement. */
final readonly class BlockPlacementStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    public function resolve(CanonicalBlockState $itemState, int $clickedFace): InternalBlockStateId
    {
        $properties = $itemState->properties();
        if (array_key_exists('pillar_axis', $properties)) {
            $properties['pillar_axis'] = BlockAxis::fromBlockFace($clickedFace)->value;
        }

        return $this->states->internalId(CanonicalBlockState::from($itemState->identifier(), $properties));
    }
}
