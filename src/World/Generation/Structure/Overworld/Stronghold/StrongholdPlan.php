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

namespace Bedriox\Server\World\Generation\Structure\Overworld\Stronghold;

use Bedriox\Server\World\Generation\Structure\Overworld\Family\PlannedStructurePiece;
use Bedriox\Server\World\Generation\Structure\PlacedStructureMarker;

final readonly class StrongholdPlan
{
    /**
     * @param non-empty-list<PlannedStructurePiece> $pieces
     * @param list<PlacedStructureMarker>            $markers
     */
    public function __construct(
        public int $centerX,
        public int $baseY,
        public int $centerZ,
        public array $pieces,
        public array $markers,
    ) {}
}
