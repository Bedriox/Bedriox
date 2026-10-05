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

namespace Bedriox\Server\Gameplay\Portal;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Simulation\Position;

final readonly class PortalDestinationPlan
{
    public function __construct(
        public WorldDimension $sourceDimension,
        public WorldDimension $targetDimension,
        public Position $projectedPosition,
        public int $searchRadius,
        public PortalAxis $preferredAxis,
    ) {}
}
