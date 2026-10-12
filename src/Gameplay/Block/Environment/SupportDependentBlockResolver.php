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

use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Data\CanonicalBlockState;
use InvalidArgumentException;

/** Pure support lookup for blocks which must break when their attachment is removed. */
final readonly class SupportDependentBlockResolver
{
    public function supportOffset(CanonicalBlockState $state): ?BlockSupportOffset
    {
        $identifier = $state->identifier();
        $properties = $state->properties();
        if (BlockTagRegistry::contains(BlockTag::RAIL, $identifier)) {
            return new BlockSupportOffset(0, -1, 0);
        }
        if (BlockTagRegistry::contains(BlockTag::DOOR, $identifier)) {
            return new BlockSupportOffset(0, -1, 0);
        }
        if (BlockTagRegistry::contains(BlockTag::BUTTON, $identifier)
            || str_ends_with($identifier, '_wall_sign')
            || $identifier === 'minecraft:wall_sign') {
            return self::oppositeFace($this->facingDirection($properties));
        }
        if (str_ends_with($identifier, '_standing_sign') || $identifier === 'minecraft:standing_sign') {
            return new BlockSupportOffset(0, -1, 0);
        }
        if (BlockTagRegistry::contains(BlockTag::HANGING_SIGN, $identifier)) {
            return ($properties['hanging'] ?? 0) === 1
                ? new BlockSupportOffset(0, 1, 0)
                : null;
        }

        return null;
    }

    public function breaksWithoutSupport(CanonicalBlockState $state, bool $supportPresent): bool
    {
        return $this->supportOffset($state) !== null && !$supportPresent;
    }

    /** @param array<string, bool|int|string> $properties */
    private function facingDirection(array $properties): int
    {
        $direction = $properties['facing_direction'] ?? null;
        if (!is_int($direction) || $direction < 0 || $direction > 5) {
            throw new InvalidArgumentException('Support-dependent block has an invalid facing direction.');
        }

        return $direction;
    }

    private static function oppositeFace(int $facingDirection): BlockSupportOffset
    {
        return match ($facingDirection) {
            0 => new BlockSupportOffset(0, 1, 0),
            1 => new BlockSupportOffset(0, -1, 0),
            2 => new BlockSupportOffset(0, 0, 1),
            3 => new BlockSupportOffset(0, 0, -1),
            4 => new BlockSupportOffset(1, 0, 0),
            5 => new BlockSupportOffset(-1, 0, 0),
            default => throw new \LogicException('Validated facing direction is outside its canonical range.'),
        };
    }
}
