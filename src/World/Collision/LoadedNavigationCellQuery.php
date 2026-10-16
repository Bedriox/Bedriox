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

namespace Bedriox\Server\World\Collision;

/** Allocation-free loaded-terrain query used by incremental entity navigation. */
interface LoadedNavigationCellQuery extends LoadedCollisionBoxQuery
{
    /** Null means at least one required chunk is not loaded. */
    public function isNavigationCellWalkableLoaded(
        int $x,
        int $y,
        int $z,
        float $width,
        float $height,
    ): ?bool;
}
