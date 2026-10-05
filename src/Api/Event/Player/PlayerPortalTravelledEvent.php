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

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\PortalType;
use Bedriox\Api\World\Position;

/** Published after the authoritative dimension transfer has committed. */
final class PlayerPortalTravelledEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly PortalType $portalType,
        public readonly Position $from,
        public readonly Position $destination,
    ) {}
}
