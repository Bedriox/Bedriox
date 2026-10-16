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
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
use InvalidArgumentException;

/** Fired after core admission checks and before a spawner submits one authoritative spawn request. */
final class SpawnerSpawnEvent extends CancellableEvent
{
    public function __construct(
        public readonly World $world,
        public readonly BlockPosition $spawnerPosition,
        public readonly string $entityIdentifier,
        public readonly Position $spawnPosition,
        public readonly bool $trial,
        public readonly bool $ominous = false,
    ) {
        if (preg_match('~^[a-z0-9_.-]+:[a-z0-9_./-]+$~D', $entityIdentifier) !== 1
            || strlen($entityIdentifier) > 128) {
            throw new InvalidArgumentException('Spawner event entity identifier must be canonical and bounded.');
        }
    }
}
