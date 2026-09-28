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

/** Canonical current-protocol baseline shared by every dropped-item actor. */
final class ItemActorMetadata
{
    private function __construct() {}

    /** @return list<ActorMetadata> */
    public static function baseline(): array
    {
        $size = self::float32(0.25);

        return [
            ActorMetadata::long(0, ActorFlag::combine(ActorFlag::HasCollision, ActorFlag::HasGravity)),
            ActorMetadata::int(1, 300),
            ActorMetadata::byte(3, 0),
            ActorMetadata::string(4, ''),
            ActorMetadata::short(7, 300),
            ActorMetadata::long(37, -1),
            ActorMetadata::float(38, 1.0),
            ActorMetadata::short(42, 300),
            ActorMetadata::float(53, $size),
            ActorMetadata::float(54, $size),
            ActorMetadata::byte(81, 0),
            ActorMetadata::long(92, 0),
            ActorMetadata::float(120, 0.0),
            ActorMetadata::vector3(130, $size, $size, $size),
        ];
    }

    private static function float32(float $value): float
    {
        $decoded = unpack('gvalue', pack('g', $value));
        if ($decoded === false || !is_float($decoded['value'])) {
            throw new \LogicException('Unable to normalize dropped-item actor metadata float.');
        }

        return $decoded['value'];
    }
}
