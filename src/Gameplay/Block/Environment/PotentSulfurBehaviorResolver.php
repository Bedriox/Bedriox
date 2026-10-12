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

/** Pure potent-sulfur transitions suitable for bounded scheduled world updates. */
final readonly class PotentSulfurBehaviorResolver
{
    public const int MAXIMUM_WATER_DEPTH = 4;

    public function __construct(private BlockStateRegistry $states) {}

    public function resolve(
        CanonicalBlockState $current,
        int $waterSourceDepth,
        PotentSulfurSubstrate $substrate,
        bool $periodicEruptionActive = false,
    ): InternalBlockStateId {
        if ($current->identifier() !== 'minecraft:potent_sulfur'
            || !array_key_exists('potent_sulfur_state', $current->properties())) {
            throw new InvalidArgumentException('Canonical block state is not potent sulfur.');
        }
        if ($waterSourceDepth < 0 || $waterSourceDepth > self::MAXIMUM_WATER_DEPTH) {
            throw new InvalidArgumentException('Potent sulfur water depth must be between zero and four source blocks.');
        }
        $state = match (true) {
            $waterSourceDepth === 0 => PotentSulfurState::DRY,
            $substrate === PotentSulfurSubstrate::SOURCE_LAVA => PotentSulfurState::CONTINUOUS,
            $substrate === PotentSulfurSubstrate::MAGMA && $periodicEruptionActive => PotentSulfurState::ERUPTING,
            $substrate === PotentSulfurSubstrate::MAGMA => PotentSulfurState::DORMANT,
            default => PotentSulfurState::WET,
        };

        return $this->states->internalId(CanonicalBlockState::from('minecraft:potent_sulfur', [
            'potent_sulfur_state' => $state->value,
        ]));
    }

    public static function hasSurfaceGas(int $waterSourceDepth): bool
    {
        return $waterSourceDepth >= 1 && $waterSourceDepth <= self::MAXIMUM_WATER_DEPTH;
    }

    public static function hasUpwardImpulse(PotentSulfurState $state): bool
    {
        return $state === PotentSulfurState::ERUPTING || $state === PotentSulfurState::CONTINUOUS;
    }
}
