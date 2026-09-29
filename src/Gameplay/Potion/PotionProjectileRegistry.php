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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use OverflowException;

/** Bounded authoritative owner for transient potion projectiles. */
final class PotionProjectileRegistry
{
    public const int DEFAULT_CAPACITY = 1_024;
    public const int MAXIMUM_CAPACITY = 8_192;
    public const int MAXIMUM_TICK_ADVANCE = 1_200;

    /** @var array<int, PotionProjectile> */
    private array $projectiles = [];
    private int $nextEntityId;

    public function __construct(
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        int $firstEntityId = 1,
    ) {
        if ($capacity < 1 || $capacity > self::MAXIMUM_CAPACITY
            || $firstEntityId < 1 || $firstEntityId >= PHP_INT_MAX) {
            throw new InvalidArgumentException('Potion projectile registry bounds are invalid.');
        }
        $this->nextEntityId = $firstEntityId;
    }

    public function spawn(
        string $ownerUuid,
        PotionType $type,
        bool $lingering,
        Position $position,
        float $yaw,
        float $pitch,
    ): PotionProjectile {
        if (count($this->projectiles) >= $this->capacity || $this->nextEntityId >= PHP_INT_MAX) {
            throw new OverflowException('Potion projectile registry is exhausted.');
        }
        if (!is_finite($yaw) || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Potion projectile rotation is invalid.');
        }
        $yawRadians = deg2rad($yaw);
        $pitchRadians = deg2rad($pitch);
        $horizontal = cos($pitchRadians);
        $speed = 0.5;
        $motion = new EntityMotion(
            -sin($yawRadians) * $horizontal * $speed,
            -sin($pitchRadians) * $speed,
            cos($yawRadians) * $horizontal * $speed,
        );
        $id = $this->nextEntityId++;
        $projectile = new PotionProjectile($id, $id, $ownerUuid, $type, $lingering, $position, $motion);
        $this->projectiles[$id] = $projectile;

        return $projectile;
    }

    public function remove(int $runtimeEntityId): ?PotionProjectile
    {
        $projectile = $this->projectiles[$runtimeEntityId] ?? null;
        unset($this->projectiles[$runtimeEntityId]);

        return $projectile;
    }

    public function spawnTippedArrow(
        string $ownerUuid,
        PotionType $type,
        Position $position,
        float $yaw,
        float $pitch,
        float $speed,
        bool $pickupAllowed = true,
    ): PotionProjectile {
        if (!is_finite($speed) || $speed < 0.1 || $speed > 3.0) {
            throw new InvalidArgumentException('Tipped-arrow speed is invalid.');
        }
        if (count($this->projectiles) >= $this->capacity || $this->nextEntityId >= PHP_INT_MAX) {
            throw new OverflowException('Potion projectile registry is exhausted.');
        }
        $yawRadians = deg2rad($yaw);
        $pitchRadians = deg2rad($pitch);
        $horizontal = cos($pitchRadians);
        $id = $this->nextEntityId++;
        $projectile = new PotionProjectile(
            $id,
            $id,
            $ownerUuid,
            $type,
            false,
            $position,
            new EntityMotion(
                -sin($yawRadians) * $horizontal * $speed,
                -sin($pitchRadians) * $speed,
                cos($yawRadians) * $horizontal * $speed,
            ),
            tippedArrow: true,
            pickupAllowed: $pickupAllowed,
        );
        $this->projectiles[$id] = $projectile;

        return $projectile;
    }

    public function restore(PotionProjectile $projectile): void
    {
        if (count($this->projectiles) >= $this->capacity || isset($this->projectiles[$projectile->runtimeEntityId])) {
            throw new OverflowException('Potion projectile restore exceeds registry bounds or duplicates identity.');
        }
        $this->projectiles[$projectile->runtimeEntityId] = $projectile;
        $this->nextEntityId = max($this->nextEntityId, $projectile->runtimeEntityId + 1);
    }

    public function get(int $runtimeEntityId): ?PotionProjectile
    {
        return $this->projectiles[$runtimeEntityId] ?? null;
    }

    /** @return list<PotionProjectile> */
    public function all(): array
    {
        return array_values($this->projectiles);
    }

    public function tick(int $ticks = 1): PotionProjectileTickResult
    {
        if ($ticks < 1 || $ticks > self::MAXIMUM_TICK_ADVANCE) {
            throw new InvalidArgumentException('Potion projectile tick advance is invalid.');
        }
        $updated = [];
        $expired = [];
        for ($tick = 0; $tick < $ticks; ++$tick) {
            foreach ($this->projectiles as $runtimeId => $projectile) {
                $projectile = $projectile->tick();
                if ($projectile->expired()) {
                    unset($this->projectiles[$runtimeId], $updated[$runtimeId]);
                    $expired[$runtimeId] = $projectile;
                } else {
                    $this->projectiles[$runtimeId] = $updated[$runtimeId] = $projectile;
                }
            }
        }

        return new PotionProjectileTickResult(array_values($updated), array_values($expired));
    }
}
