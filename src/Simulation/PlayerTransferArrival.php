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

namespace Bedriox\Server\Simulation;

use Bedriox\Server\Player\Player;
use Bedriox\Server\Simulation\Event\WorldEvent;

/** Authoritative player aggregate and bounded destination-world projection work. */
final readonly class PlayerTransferArrival
{
    /** @param list<WorldEvent> $events */
    public function __construct(
        public Player $player,
        public array $events,
    ) {}
}
