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

/** Placement and asymmetric support rules for sulfur stalactites and stalagmites. */
final readonly class SulfurSpikeBehaviorResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    public function place(int $clickedFace): InternalBlockStateId
    {
        $hanging = match ($clickedFace) {
            0 => 1,
            1 => 0,
            default => throw new InvalidArgumentException('Sulfur spikes require floor or ceiling support.'),
        };

        return $this->states->internalId(CanonicalBlockState::from('minecraft:sulfur_spike', [
            'dripstone_thickness' => 'tip',
            'hanging' => $hanging,
        ]));
    }

    public function supportAction(CanonicalBlockState $state, bool $supportPresent): SulfurSpikeSupportAction
    {
        if ($state->identifier() !== 'minecraft:sulfur_spike'
            || !array_key_exists('hanging', $state->properties())) {
            throw new InvalidArgumentException('Canonical block state is not a sulfur spike.');
        }
        if ($supportPresent) {
            return SulfurSpikeSupportAction::STABLE;
        }

        return $state->properties()['hanging'] === 1
            ? SulfurSpikeSupportAction::FALL
            : SulfurSpikeSupportAction::BREAK;
    }

    public static function breaksFromProjectile(string $projectileIdentifier): bool
    {
        return in_array($projectileIdentifier, ['minecraft:trident', 'minecraft:thrown_trident'], true);
    }
}
