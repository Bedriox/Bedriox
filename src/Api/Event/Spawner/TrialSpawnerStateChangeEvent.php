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

namespace Bedriox\Api\Event\Spawner;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Spawner\TrialSpawnerPhase;
use Bedriox\Api\World\World;

/** Fired before a trial spawner commits a phase transition. */
final class TrialSpawnerStateChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly World $world,
        public readonly BlockPosition $position,
        public readonly TrialSpawnerPhase $oldPhase,
        public readonly TrialSpawnerPhase $newPhase,
        public readonly bool $ominous,
    ) {}
}
