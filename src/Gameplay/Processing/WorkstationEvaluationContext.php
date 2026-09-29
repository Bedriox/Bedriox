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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Api\Processing\CartographyOperation;
use InvalidArgumentException;

/** Bounded player intent and authoritative balances used to preview one workstation operation. */
final readonly class WorkstationEvaluationContext
{
    public function __construct(
        public ?int $recipeSourceIndex = null,
        public ?string $name = null,
        public int $maximumDurability = 0,
        public int $enchantingOption = 0,
        public int $bookshelves = 0,
        public int $enchantmentSeed = 0,
        public int $playerLevel = 0,
        public int $availableLapis = 0,
        public ?string $loomPattern = null,
        public ?CartographyOperation $cartographyOperation = null,
    ) {
        if (($recipeSourceIndex !== null && ($recipeSourceIndex < 0 || $recipeSourceIndex > 1_000_000))
            || ($name !== null && (preg_match('//u', $name) !== 1 || mb_strlen($name) > 50))
            || $maximumDurability < 0 || $maximumDurability > 65_535
            || $enchantingOption < 0 || $enchantingOption > 2
            || $bookshelves < 0 || $bookshelves > 15
            || $enchantmentSeed < 0
            || $playerLevel < 0 || $playerLevel > 1_000_000
            || $availableLapis < 0 || $availableLapis > 64
            || ($loomPattern !== null && preg_match('/^[a-z0-9_]{1,32}$/D', $loomPattern) !== 1)) {
            throw new InvalidArgumentException('Workstation evaluation context is outside its supported bounds.');
        }
    }
}
