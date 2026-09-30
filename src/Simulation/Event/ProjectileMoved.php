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

use Bedriox\Server\Gameplay\Projectile\Projectile;

final readonly class ProjectileMoved implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public Projectile $projectile,
        public array $recipientSessionIds,
        public bool $motionChanged = false,
        public bool $embedded = false,
    ) {}
    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
