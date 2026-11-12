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

namespace Bedriox\Api\Map;

use Bedriox\Api\World\BlockFace;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;

interface MapManager
{
    public function createTerrain(Position $center, MapScale $scale = MapScale::CLOSEST): MapView;

    public function createImage(MapImage $image): MapView;

    public function get(int $id): ?MapView;

    /** Places one authoritative map-bearing frame at an exact block position. */
    public function placeFrame(
        BlockPosition $position,
        BlockFace $facing,
        MapView $map,
        bool $glowing = false,
    ): void;
}
