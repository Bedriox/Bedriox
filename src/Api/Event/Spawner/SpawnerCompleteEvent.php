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

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\World;
use InvalidArgumentException;

/** Observes the committed completion of a trial-spawner encounter. */
final class SpawnerCompleteEvent extends Event implements PostEvent
{
    /** @param list<string> $participantIds */
    public function __construct(
        public readonly World $world,
        public readonly BlockPosition $position,
        public readonly array $participantIds,
        public readonly bool $ominous,
    ) {
        if ($participantIds === [] || count($participantIds) > 64
            || count(array_unique($participantIds)) !== count($participantIds)) {
            throw new InvalidArgumentException('Spawner completion participants must be a unique bounded list.');
        }
        foreach ($participantIds as $participantId) {
            if ($participantId === '' || strlen($participantId) > 64 || preg_match('//u', $participantId) !== 1) {
                throw new InvalidArgumentException('Spawner completion participant identity is invalid.');
            }
        }
    }
}
