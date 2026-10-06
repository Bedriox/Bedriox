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

use Bedriox\Server\Entity\Vanilla\End\EnderDragonEntity;
use Bedriox\Server\Simulation\Position;

/** Stable wire identities and transforms for the client-targetable parts of one dragon. */
final readonly class EnderDragonPartProjection
{
    private const int ACTOR_ID_BASE = 1_000_000_000_000;
    private const int PARTS_PER_DRAGON = 4;

    private function __construct(
        public int $runtimeActorId,
        public EnderDragonPart $part,
        public Position $position,
        public float $width,
        public float $height,
    ) {}

    /** @return list<self> */
    public static function forDragon(EnderDragonEntity $dragon): array
    {
        $origin = $dragon->internalPosition();
        $yaw = deg2rad($dragon->getYaw());
        $forwardX = -sin($yaw);
        $forwardZ = cos($yaw);
        $sideX = $forwardZ;
        $sideZ = -$forwardX;

        return [
            self::at($dragon, 0, EnderDragonPart::HEAD, $origin, $forwardX * 6.0, 2.4, $forwardZ * 6.0, 2.5, 2.5),
            self::at($dragon, 1, EnderDragonPart::WING, $origin, $sideX * 5.0, 1.5, $sideZ * 5.0, 4.5, 2.0),
            self::at($dragon, 2, EnderDragonPart::WING, $origin, -$sideX * 5.0, 1.5, -$sideZ * 5.0, 4.5, 2.0),
            self::at($dragon, 3, EnderDragonPart::TAIL, $origin, -$forwardX * 6.0, 1.2, -$forwardZ * 6.0, 3.0, 2.0),
        ];
    }

    public static function resolve(EnderDragonEntity $dragon, int $runtimeActorId): ?EnderDragonPart
    {
        if ($runtimeActorId === $dragon->getRuntimeId()) {
            return EnderDragonPart::BODY;
        }
        foreach (self::forDragon($dragon) as $projection) {
            if ($projection->runtimeActorId === $runtimeActorId) {
                return $projection->part;
            }
        }

        return null;
    }

    public static function find(EnderDragonEntity $dragon, int $runtimeActorId): ?self
    {
        foreach (self::forDragon($dragon) as $projection) {
            if ($projection->runtimeActorId === $runtimeActorId) {
                return $projection;
            }
        }

        return null;
    }

    public static function parentRuntimeId(int $runtimeActorId): ?int
    {
        $relative = $runtimeActorId - self::ACTOR_ID_BASE;
        if ($relative < self::PARTS_PER_DRAGON) {
            return null;
        }
        $parent = intdiv($relative, self::PARTS_PER_DRAGON);

        return $parent > 0 && $parent < PHP_INT_MAX ? $parent : null;
    }

    private static function at(
        EnderDragonEntity $dragon,
        int $slot,
        EnderDragonPart $part,
        Position $origin,
        float $offsetX,
        float $offsetY,
        float $offsetZ,
        float $width,
        float $height,
    ): self {
        return new self(
            self::ACTOR_ID_BASE + ($dragon->getRuntimeId() * self::PARTS_PER_DRAGON) + $slot,
            $part,
            new Position($origin->x + $offsetX, $origin->y + $offsetY, $origin->z + $offsetZ),
            $width,
            $height,
        );
    }
}
