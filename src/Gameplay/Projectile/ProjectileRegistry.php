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

namespace Bedriox\Server\Gameplay\Projectile;

use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use OverflowException;

/** Bounded authoritative owner for transient projectiles. */
final class ProjectileRegistry
{
    public const int DEFAULT_CAPACITY = 1_024;
    public const int MAXIMUM_CAPACITY = 8_192;
    public const int MAXIMUM_TICK_ADVANCE = 1_200;

    /** @var array<int, Projectile> */
    private array $projectiles = [];
    private int $nextEntityId;

    public function __construct(
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        int $firstEntityId = 1,
    ) {
        if ($capacity < 1 || $capacity > self::MAXIMUM_CAPACITY
            || $firstEntityId < 1 || $firstEntityId >= PHP_INT_MAX) {
            throw new InvalidArgumentException('Projectile registry bounds are invalid.');
        }
        $this->nextEntityId = $firstEntityId;
    }

    public function spawn(
        string $ownerUuid,
        int $ownerRuntimeEntityId,
        PotionType $type,
        bool $lingering,
        Position $position,
        float $yaw,
        float $pitch,
        ProjectileOwnerType $ownerType = ProjectileOwnerType::PLAYER,
    ): Projectile {
        if (count($this->projectiles) >= $this->capacity || $this->nextEntityId >= PHP_INT_MAX) {
            throw new OverflowException('Projectile registry is exhausted.');
        }
        if (!is_finite($yaw) || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Projectile rotation is invalid.');
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
        $projectile = new Projectile(
            $id,
            $id,
            $ownerUuid,
            $type,
            $lingering,
            $position,
            $motion,
            type: $lingering ? ProjectileType::LINGERING_POTION : ProjectileType::SPLASH_POTION,
            ownerRuntimeEntityId: $ownerRuntimeEntityId,
            yaw: $yaw,
            pitch: $pitch,
            ownerType: $ownerType,
        );
        $this->projectiles[$id] = $projectile;

        return $projectile;
    }

    public function remove(int $runtimeEntityId): ?Projectile
    {
        $projectile = $this->projectiles[$runtimeEntityId] ?? null;
        unset($this->projectiles[$runtimeEntityId]);

        return $projectile;
    }

    public function spawnTippedArrow(
        string $ownerUuid,
        int $ownerRuntimeEntityId,
        PotionType $type,
        Position $position,
        float $yaw,
        float $pitch,
        float $speed,
        ArrowPickupMode $pickupMode = ArrowPickupMode::ANY,
        float $damageBonus = 0.0,
        float $knockbackStrength = 0.4,
        int $fireTicks = 0,
        int $piercingLevel = 0,
        ProjectileOwnerType $ownerType = ProjectileOwnerType::PLAYER,
    ): Projectile {
        return $this->spawnArrow(
            $ownerUuid,
            $ownerRuntimeEntityId,
            $position,
            $yaw,
            $pitch,
            $speed,
            $pickupMode,
            $damageBonus,
            $knockbackStrength,
            $fireTicks,
            $piercingLevel,
            $type,
            $ownerType,
        );
    }

    public function spawnArrow(
        string $ownerUuid,
        int $ownerRuntimeEntityId,
        Position $position,
        float $yaw,
        float $pitch,
        float $speed,
        ArrowPickupMode $pickupMode = ArrowPickupMode::ANY,
        float $damageBonus = 0.0,
        float $knockbackStrength = 0.4,
        int $fireTicks = 0,
        int $piercingLevel = 0,
        ?PotionType $potionType = null,
        ProjectileOwnerType $ownerType = ProjectileOwnerType::PLAYER,
    ): Projectile {
        if (!is_finite($speed) || $speed < 0.1 || $speed > 3.2) {
            throw new InvalidArgumentException('Arrow speed is invalid.');
        }
        if (count($this->projectiles) >= $this->capacity || $this->nextEntityId >= PHP_INT_MAX) {
            throw new OverflowException('Projectile registry is exhausted.');
        }
        $yawRadians = deg2rad($yaw);
        $pitchRadians = deg2rad($pitch);
        $horizontal = cos($pitchRadians);
        $id = $this->nextEntityId++;
        $projectile = new Projectile(
            $id,
            $id,
            $ownerUuid,
            $potionType ?? PotionType::WATER,
            false,
            $position,
            new EntityMotion(
                -sin($yawRadians) * $horizontal * $speed,
                -sin($pitchRadians) * $speed,
                cos($yawRadians) * $horizontal * $speed,
            ),
            tippedArrow: $potionType !== null,
            pickupAllowed: $pickupMode !== ArrowPickupMode::NONE,
            damageBonus: $damageBonus,
            knockbackStrength: $knockbackStrength,
            fireTicks: $fireTicks,
            type: ProjectileType::ARROW,
            piercingRemaining: $piercingLevel,
            ownerRuntimeEntityId: $ownerRuntimeEntityId,
            ownerType: $ownerType,
            yaw: $yaw,
            pitch: $pitch,
            pickupMode: $pickupMode,
        );
        $this->projectiles[$id] = $projectile;

        return $projectile;
    }

    public function spawnTrident(
        string $ownerUuid,
        int $ownerRuntimeEntityId,
        PotionType $fallbackPotionType,
        Position $position,
        float $yaw,
        float $pitch,
        float $speed,
        float $damageBonus,
        int $loyaltyLevel,
        bool $channeling,
        bool $pickupAllowed,
        InventoryStack $carriedItem,
    ): Projectile {
        if (!is_finite($speed) || $speed < 0.1 || $speed > 2.4
            || !is_finite($damageBonus) || $damageBonus < 0.0 || $damageBonus > 64.0) {
            throw new InvalidArgumentException('Trident projectile input is invalid.');
        }
        if (count($this->projectiles) >= $this->capacity || $this->nextEntityId >= PHP_INT_MAX) {
            throw new OverflowException('Projectile registry is exhausted.');
        }
        $yawRadians = deg2rad($yaw);
        $pitchRadians = deg2rad($pitch);
        $horizontal = cos($pitchRadians);
        $id = $this->nextEntityId++;
        $projectile = new Projectile(
            $id,
            $id,
            $ownerUuid,
            $fallbackPotionType,
            false,
            $position,
            new EntityMotion(
                -sin($yawRadians) * $horizontal * $speed,
                -sin($pitchRadians) * $speed,
                cos($yawRadians) * $horizontal * $speed,
            ),
            pickupAllowed: $pickupAllowed,
            damageBonus: $damageBonus,
            knockbackStrength: 0.4,
            type: ProjectileType::TRIDENT,
            loyaltyLevel: $loyaltyLevel,
            channeling: $channeling,
            carriedItem: $carriedItem,
            ownerRuntimeEntityId: $ownerRuntimeEntityId,
            yaw: $yaw,
            pitch: $pitch,
        );
        $this->projectiles[$id] = $projectile;

        return $projectile;
    }

    public function spawnFishingHook(
        string $ownerUuid,
        int $ownerRuntimeEntityId,
        Position $position,
        float $yaw,
        float $pitch,
        int $luckLevel,
        int $lureLevel,
    ): Projectile {
        if ($ownerRuntimeEntityId < 1 || !is_finite($yaw) || !is_finite($pitch)
            || $pitch < -90.0 || $pitch > 90.0 || $luckLevel < 0 || $luckLevel > 3
            || $lureLevel < 0 || $lureLevel > 3) {
            throw new InvalidArgumentException('Fishing-hook input is invalid.');
        }
        if (count($this->projectiles) >= $this->capacity || $this->nextEntityId >= PHP_INT_MAX) {
            throw new OverflowException('Projectile registry is exhausted.');
        }
        $yawRadians = deg2rad($yaw);
        $pitchRadians = deg2rad($pitch);
        $horizontal = cos($pitchRadians);
        $id = $this->nextEntityId++;
        $projectile = new Projectile(
            $id,
            $id,
            $ownerUuid,
            PotionType::WATER,
            false,
            $position,
            new EntityMotion(
                -sin($yawRadians) * $horizontal * 1.5,
                -sin($pitchRadians) * 1.5,
                cos($yawRadians) * $horizontal * 1.5,
            ),
            pickupAllowed: false,
            type: ProjectileType::FISHING_HOOK,
            ownerRuntimeEntityId: $ownerRuntimeEntityId,
            fishingLuckLevel: $luckLevel,
            fishingLureLevel: $lureLevel,
            yaw: $yaw,
            pitch: $pitch,
        );
        $this->projectiles[$id] = $projectile;

        return $projectile;
    }

    public function restore(Projectile $projectile): void
    {
        if (count($this->projectiles) >= $this->capacity || isset($this->projectiles[$projectile->runtimeEntityId])) {
            throw new OverflowException('Projectile restore exceeds registry bounds or duplicates identity.');
        }
        $this->projectiles[$projectile->runtimeEntityId] = $projectile;
        $this->nextEntityId = max($this->nextEntityId, $projectile->runtimeEntityId + 1);
    }

    public function get(int $runtimeEntityId): ?Projectile
    {
        return $this->projectiles[$runtimeEntityId] ?? null;
    }

    public function replace(Projectile $projectile): void
    {
        if (!isset($this->projectiles[$projectile->runtimeEntityId])) {
            throw new InvalidArgumentException('Projectile is not registered.');
        }
        $this->projectiles[$projectile->runtimeEntityId] = $projectile;
    }

    /** @return list<Projectile> */
    public function all(): array
    {
        return array_values($this->projectiles);
    }

    public function tick(int $ticks = 1): ProjectileTickResult
    {
        if ($ticks < 1 || $ticks > self::MAXIMUM_TICK_ADVANCE) {
            throw new InvalidArgumentException('Projectile tick advance is invalid.');
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

        return new ProjectileTickResult(array_values($updated), array_values($expired));
    }
}
