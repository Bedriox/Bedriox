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

namespace Bedriox\Server\Simulation\Command;

use InvalidArgumentException;

/** Server-resolved recipe intent carried alongside one atomic inventory request. */
final readonly class CraftingRequest
{
    public function __construct(
        public int $recipeNetworkId,
        public int $repetitions,
        public bool $automatic = false,
    ) {
        if ($recipeNetworkId < 1 || $repetitions < 1 || $repetitions > 64) {
            throw new InvalidArgumentException('Crafting request values are outside their supported bounds.');
        }
    }
}
