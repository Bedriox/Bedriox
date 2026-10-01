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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\Value\EntityBlockChangeReason;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\World\Block;
use InvalidArgumentException;

/** Immutable observation after an entity-owned block replacement commits. */
final class EntityBlockChangedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Entity $entity,
        public readonly Block $from,
        public readonly Block $to,
        public readonly EntityBlockChangeReason $reason,
    ) {
        if ($from->position != $to->position) {
            throw new InvalidArgumentException('Committed entity block changes must retain one block position.');
        }
    }
}
