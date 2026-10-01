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

namespace Bedriox\Api\Entity\Controller;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Player\Player;

interface AngerableController extends MobController
{
    /**
     * Sets or clears the authoritative anger target.
     *
     * A null target requires zero ticks. A non-null target requires a positive,
     * implementation-bounded duration.
     */
    public function setAngerTarget(Entity|Player|null $target, int $ticks): void;
}
