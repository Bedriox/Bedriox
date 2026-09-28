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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\World;

/** @internal Mutable composition bridge populated once the default runtime has been assembled. */
final class WorldHandleResolver
{
    private ?WorldRuntimeManager $worlds = null;

    public function attach(WorldRuntimeManager $worlds): void
    {
        $this->worlds = $worlds;
    }

    public function resolve(string $worldId): ?World
    {
        return $this->worlds?->get($worldId)?->handle;
    }
}
