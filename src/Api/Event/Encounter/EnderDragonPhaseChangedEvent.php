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
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;

final class EnderDragonPhaseChangedEvent extends Event implements PostEvent
{
    public function __construct(public readonly EnderDragonEncounter $encounter, public readonly EnderDragonPhase $from, public readonly EnderDragonPhase $to) {}
}
