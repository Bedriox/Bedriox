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

namespace Bedriox\Server\World\Environment;

use Bedriox\Server\World\BlockPosition;

final readonly class ScheduledEnvironmentTick
{
    public function __construct(
        public BlockPosition $position,
        public EnvironmentTickType $type,
        public int $dueTick,
    ) {}

    public function key(): string
    {
        return $this->type->value . ':' . $this->position->x . ':' . $this->position->y . ':' . $this->position->z;
    }
}
