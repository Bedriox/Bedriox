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
use Bedriox\Api\Encounter\EnderDragonPhase;
use Bedriox\Api\Event\CancellableEvent;

final class EnderDragonPhaseChangeEvent extends CancellableEvent
{
    public function __construct(public readonly EnderDragonEncounter $encounter, public readonly EnderDragonPhase $from, private EnderDragonPhase $to) {}

    public function to(): EnderDragonPhase
    {
        return $this->to;
    }

    public function setTo(EnderDragonPhase $phase): void
    {
        $this->assertMutable();
        $this->to = $phase;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->to];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof EnderDragonPhase) {
            throw new \InvalidArgumentException('Invalid dragon phase-change event state.');
        }
        parent::replaceState($state[0]);
        $this->to = $state[1];
    }
}
