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

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Gameplay\Portal\PortalAxis;
use Bedriox\Server\Gameplay\Portal\PortalDestinationPlan;
use Bedriox\Server\World\BlockPosition;

/** Narrow simulation-to-runtime intent; the runtime owns destination readiness and the actual dimension transfer. */
final readonly class PortalTransferRequested implements WorldEvent
{
    public function __construct(
        public string $sessionId,
        public BlockPosition $entryBlock,
        public PortalAxis $sourceAxis,
        public PortalDestinationPlan $destination,
    ) {}

    public function recipients(): array
    {
        return [$this->sessionId];
    }
}
