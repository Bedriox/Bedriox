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

namespace Bedriox\Server\World\Environment\Fluid;

use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\World;

/** Loaded-chunk-only fluid view; environmental planning never loads or generates terrain. */
final readonly class LoadedWorldFluidView implements FluidWorldView
{
    public function __construct(
        private World $world,
        private BlockStateRegistry $states,
        private BlockCollisionRegistry $collisions,
    ) {}

    public function cellAt(BlockPosition $position): ?FluidCell
    {
        $state = $this->world->loadedBlockStateAt($position->x, $position->y, $position->z);
        if ($state === null) {
            return null;
        }
        $canonical = $this->states->state($state);
        $shape = $this->collisions->find($state);
        $fluid = FluidState::fromCanonical($canonical);

        return new FluidCell(
            $canonical,
            $fluid !== null || $shape?->isEmpty() === true,
            $shape?->highestY() === 1.0,
        );
    }
}
