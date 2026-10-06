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

namespace Bedriox\Api\Event\Encounter;

use Bedriox\Api\Encounter\EnderDragonEncounter;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\BlockPosition;

final class EndGatewayCreateEvent extends CancellableEvent
{
    public function __construct(public readonly EnderDragonEncounter $encounter, private BlockPosition $position, public readonly int $index) {}

    public function position(): BlockPosition
    {
        return $this->position;
    }

    public function setPosition(BlockPosition $position): void
    {
        $this->assertMutable();
        $this->position = $position;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->position];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof BlockPosition) {
            throw new \InvalidArgumentException('Invalid End gateway event state.');
        }
        parent::replaceState($state[0]);
        $this->position = $state[1];
    }
}
