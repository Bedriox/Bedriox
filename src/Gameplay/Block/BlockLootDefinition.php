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

use InvalidArgumentException;

/** Immutable loot, experience and committed-break behavior for one vanilla block family member. */
final readonly class BlockLootDefinition
{
    public function __construct(
        public BlockLootKind $kind,
        public ?string $dropIdentifier = null,
        public int $minimumCount = 0,
        public int $maximumCount = 0,
        public ?string $silkTouchIdentifier = null,
        public int $minimumExperience = 0,
        public int $maximumExperience = 0,
        public BlockBreakEffect $breakEffect = BlockBreakEffect::None,
        public bool $experienceWithSilkTouch = false,
        public ?string $experienceStateProperty = null,
        public int|string|null $experienceStateValue = null,
    ) {
        foreach ([$dropIdentifier, $silkTouchIdentifier] as $identifier) {
            if ($identifier !== null && preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
                throw new InvalidArgumentException('Block loot identifiers must be canonical and namespaced.');
            }
        }
        if ($minimumCount < 0 || $maximumCount < $minimumCount || $maximumCount > 64) {
            throw new InvalidArgumentException('Block loot count range is invalid.');
        }
        if ($minimumExperience < 0 || $maximumExperience < $minimumExperience || $maximumExperience > 100) {
            throw new InvalidArgumentException('Block experience range is invalid.');
        }
        if (($experienceStateProperty === null) !== ($experienceStateValue === null)
            || ($experienceStateProperty !== null
                && preg_match('/^[a-z0-9_.:-]+$/D', $experienceStateProperty) !== 1)) {
            throw new InvalidArgumentException('Block experience state qualifier is invalid.');
        }
        if ($kind !== BlockLootKind::None && $dropIdentifier === null) {
            throw new InvalidArgumentException('A producing block loot definition needs an item identifier.');
        }
    }
}
