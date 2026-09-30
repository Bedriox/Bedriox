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
use Bedriox\Api\Entity\EntityTargetReason;
use Bedriox\Api\Entity\Mob;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable, adjustable target transition before authoritative commit. */
final class EntityTargetEvent extends CancellableEvent
{
    public function __construct(
        public readonly Mob $entity,
        public readonly Entity|Player|null $previousTarget,
        private Entity|Player|null $target,
        public readonly EntityTargetReason $reason,
    ) {}

    public function target(): Entity|Player|null
    {
        return $this->target;
    }

    public function setTarget(Entity|Player|null $target): void
    {
        $this->assertMutable();
        $this->target = $target;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->target];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0])
            || ($state[1] !== null && !$state[1] instanceof Entity && !$state[1] instanceof Player)) {
            throw new InvalidArgumentException('Invalid entity target event state.');
        }
        parent::replaceState($state[0]);
        $this->target = $state[1];
    }
}
