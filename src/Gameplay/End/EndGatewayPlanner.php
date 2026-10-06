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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Deterministic vanilla-shaped ring of twenty paired End gateway destinations. */
final class EndGatewayPlanner
{
    public const int GATEWAY_COUNT = 20;
    public const int INNER_RADIUS = 96;
    public const int OUTER_RADIUS = 1_024;

    /** @return list<int> */
    public static function order(int $seed): array
    {
        $order = range(0, self::GATEWAY_COUNT - 1);
        for ($index = self::GATEWAY_COUNT - 1; $index > 0; --$index) {
            $sample = hexdec(substr(hash('sha256', $seed . ':end_gateway:' . $index), 0, 8));
            $swap = ($sample & 0x7fff_ffff) % ($index + 1);
            [$order[$index], $order[$swap]] = [$order[$swap], $order[$index]];
        }

        return $order;
    }

    public static function inner(int $slot): BlockPosition
    {
        return self::atRadius($slot, self::INNER_RADIUS, 75);
    }

    public static function outer(int $slot): BlockPosition
    {
        return self::atRadius($slot, self::OUTER_RADIUS, 75);
    }

    private static function atRadius(int $slot, int $radius, int $y): BlockPosition
    {
        if ($slot < 0 || $slot >= self::GATEWAY_COUNT) {
            throw new InvalidArgumentException('End gateway slot is outside the supported ring.');
        }
        $angle = (2.0 * M_PI * $slot / self::GATEWAY_COUNT) - (M_PI / 2.0);

        return new BlockPosition((int) round(cos($angle) * $radius), $y, (int) round(sin($angle) * $radius));
    }
}
