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

namespace Bedriox\Api\Entity;

use Bedriox\Api\World\Position;
use LogicException;

/** @internal */
final class UnavailableEntityRegistrar implements EntityRegistrar
{
    public function register(CustomMobDefinition $definition, bool $replace = false): void
    {
        throw new LogicException('Entity registration is unavailable in this plugin context.');
    }

    public function spawn(CustomEntityType $type, Position $position, float $yaw = 0.0, float $pitch = 0.0): void
    {
        throw new LogicException('Entity spawning is unavailable in this plugin context.');
    }
}
