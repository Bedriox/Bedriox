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

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;

/** Bounded nutrition and residue proposed by one completed consumption. */
final readonly class ConsumptionResult
{
    private const int MAX_RESIDUE_STACKS = 8;

    /** @param list<ItemStack> $residue */
    public function __construct(
        public int $foodRestore,
        public float $saturationRestore,
        public array $residue = [],
    ) {
        if ($foodRestore < 0 || $foodRestore > 20) {
            throw new InvalidArgumentException('Food restore must be between 0 and 20.');
        }
        if (!is_finite($saturationRestore) || $saturationRestore < 0.0 || $saturationRestore > 20.0) {
            throw new InvalidArgumentException('Saturation restore must be finite and between 0 and 20.');
        }
        self::validateResidue($residue);
    }

    private static function validateResidue(mixed $residue): void
    {
        if (!is_array($residue) || !array_is_list($residue)) {
            throw new InvalidArgumentException('Consumption residue must be a list.');
        }
        if (count($residue) > self::MAX_RESIDUE_STACKS) {
            throw new InvalidArgumentException('Consumption residue may contain at most eight stacks.');
        }
        foreach ($residue as $stack) {
            self::validateResidueStack($stack);
        }
    }

    private static function validateResidueStack(mixed $stack): void
    {
        if (!$stack instanceof ItemStack) {
            throw new InvalidArgumentException('Consumption residue must contain only item stacks.');
        }
    }
}
