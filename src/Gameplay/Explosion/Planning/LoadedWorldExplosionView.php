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

namespace Bedriox\Server\Gameplay\Explosion\Planning;

use Bedriox\Data\BlockPropertyRegistry;
use Bedriox\Server\Gameplay\Explosion\Value\ExplosionBlockSample;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\World;

/** Read-only loaded-chunk explosion view with conservative vanilla material resistance groups. */
final readonly class LoadedWorldExplosionView implements ExplosionWorldView
{
    public function __construct(
        private World $world,
        private BlockStateRegistry $states,
        private BlockPropertyRegistry $properties,
    ) {}

    public function sample(BlockPosition $position): ?ExplosionBlockSample
    {
        $state = $this->world->loadedBlockStateAt($position->x, $position->y, $position->z);
        if ($state === null) {
            return null;
        }
        $identifier = $this->states->state($state)->identifier();
        if ($identifier === 'minecraft:air') {
            return ExplosionBlockSample::air();
        }

        return new ExplosionBlockSample(
            $identifier,
            $this->properties->propertiesForState($this->states->state($state))->explosionResistance(),
        );
    }
}
