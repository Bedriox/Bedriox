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

/** Shared brushing state rules for suspicious sand and suspicious gravel. */
final readonly class BrushableBlockBehaviorResolver
{
    private InternalBlockStateId $sand;
    private InternalBlockStateId $gravel;

    public function __construct(private BlockStateRegistry $states)
    {
        $this->sand = $states->internalId(CanonicalBlockState::from('minecraft:sand'));
        $this->gravel = $states->internalId(CanonicalBlockState::from('minecraft:gravel'));
    }

    public function supports(CanonicalBlockState|string $block): bool
    {
        $identifier = $block instanceof CanonicalBlockState ? $block->identifier() : $block;

        return $identifier === 'minecraft:suspicious_sand' || $identifier === 'minecraft:suspicious_gravel';
    }

    public function withProgress(CanonicalBlockState $state, int $progress): InternalBlockStateId
    {
        if (!$this->supports($state) || $progress < 0 || $progress > 3) {
            throw new InvalidArgumentException('Brushable block state or progress is invalid.');
        }
        $properties = $state->properties();
        $properties['brushed_progress'] = $progress;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    public function exhaustedReplacement(CanonicalBlockState $state): InternalBlockStateId
    {
        return match ($state->identifier()) {
            'minecraft:suspicious_sand' => $this->sand,
            'minecraft:suspicious_gravel' => $this->gravel,
            default => throw new InvalidArgumentException('Canonical block state is not brushable.'),
        };
    }
}
