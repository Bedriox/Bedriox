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

use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;

final class FallingBlockActorMetadata
{
    private function __construct() {}

    /** @return list<ActorMetadata> */
    public static function baseline(int $blockNetworkRuntimeId, bool $moving = true): array
    {
        return [
            self::flags($moving),
            ActorMetadata::int(1, 300),
            ActorMetadata::int(2, $blockNetworkRuntimeId),
            ActorMetadata::byte(3, 0),
            ActorMetadata::string(4, ''),
            ActorMetadata::short(7, 300),
            ActorMetadata::long(37, -1),
            ActorMetadata::float(38, 1.0),
            ActorMetadata::short(42, 300),
            ActorMetadata::float(53, 0.98),
            ActorMetadata::float(54, 0.98),
            ActorMetadata::byte(81, 0),
            ActorMetadata::long(92, 0),
            ActorMetadata::float(120, 0.0),
            ActorMetadata::vector3(130, 0.98, 0.98, 0.98),
        ];
    }

    public static function flags(bool $moving): ActorMetadata
    {
        return ActorMetadata::long(0, ActorFlag::combine(
            ActorFlag::HasCollision,
            ActorFlag::HasGravity,
            ...($moving ? [ActorFlag::Moving] : []),
        ));
    }
}
