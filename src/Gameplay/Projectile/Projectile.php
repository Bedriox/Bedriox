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
use Bedriox\Api\World\BlockFace;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Immutable authoritative projectile snapshot shared by arrows, thrown items, hooks, and potions. */
final readonly class Projectile
{
    public const int MAXIMUM_LIFETIME_TICKS = 1_200;

    public function __construct(
        public int $uniqueEntityId,
        public int $runtimeEntityId,
        public string $ownerUuid,
        public PotionType $potionType,
        public bool $lingering,
        public Position $position,
        public EntityMotion $motion,
        public int $ageTicks = 0,
        public bool $tippedArrow = false,
        public bool $pickupAllowed = true,
        public float $damageBonus = 0.0,
        public float $knockbackStrength = 0.4,
        public int $fireTicks = 0,
        public ProjectileType $type = ProjectileType::SPLASH_POTION,
        public int $piercingRemaining = 0,
        /** @var list<string> */
        public array $hitActorKeys = [],
        public int $loyaltyLevel = 0,
        public bool $channeling = false,
        public ?InventoryStack $carriedItem = null,
        public int $ownerRuntimeEntityId = 0,
        public ProjectileOwnerType $ownerType = ProjectileOwnerType::PLAYER,
        public bool $fishingBobbing = false,
        public int $fishingWaitTicks = 0,
        public int $fishingBiteTicks = 0,
        public int $fishingLuckLevel = 0,
        public int $fishingLureLevel = 0,
        public ProjectileState $state = ProjectileState::FLYING,
        public float $yaw = 0.0,
        public float $pitch = 0.0,
        public ?BlockPosition $embeddedBlock = null,
        public ?BlockFace $embeddedFace = null,
        public int $embeddedTicks = 0,
        public ArrowPickupMode $pickupMode = ArrowPickupMode::ANY,
    ) {
        if ($uniqueEntityId < 1 || $uniqueEntityId >= PHP_INT_MAX
            || $runtimeEntityId < 1 || $runtimeEntityId >= PHP_INT_MAX || $ownerUuid === ''
            || strlen($ownerUuid) > 64 || preg_match('//u', $ownerUuid) !== 1) {
            throw new InvalidArgumentException('Projectile identity is invalid.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 30_000_000.0 || $ageTicks < 0 || $ageTicks > self::MAXIMUM_LIFETIME_TICKS
            || !is_finite($damageBonus) || $damageBonus < 0.0 || $damageBonus > 64.0
            || !is_finite($knockbackStrength) || $knockbackStrength < 0.0 || $knockbackStrength > 16.0
            || $fireTicks < 0 || $fireTicks > 0x7fff || $piercingRemaining < 0 || $piercingRemaining > 5
            || count($hitActorKeys) > 5 || $loyaltyLevel < 0 || $loyaltyLevel > 3
            || $ownerRuntimeEntityId < 1 || $ownerRuntimeEntityId >= PHP_INT_MAX
            || $fishingWaitTicks < 0 || $fishingWaitTicks > 1_200
            || $fishingBiteTicks < 0 || $fishingBiteTicks > 200
            || $fishingLuckLevel < 0 || $fishingLuckLevel > 3
            || $fishingLureLevel < 0 || $fishingLureLevel > 3
            || !is_finite($yaw) || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0
            || $embeddedTicks < 0 || $embeddedTicks > self::MAXIMUM_LIFETIME_TICKS
            || (($embeddedBlock === null) !== ($embeddedFace === null))
            || ($state === ProjectileState::EMBEDDED && $embeddedBlock === null)
            || ($state !== ProjectileState::EMBEDDED && ($embeddedBlock !== null || $embeddedTicks !== 0))
            || ($type !== ProjectileType::ARROW && $pickupMode !== ArrowPickupMode::ANY)
            || ($type !== ProjectileType::FISHING_HOOK && ($fishingBobbing || $fishingWaitTicks > 0
                || $fishingBiteTicks > 0 || $fishingLuckLevel > 0 || $fishingLureLevel > 0))) {
            throw new InvalidArgumentException('Projectile state is outside its supported bounds.');
        }
        foreach ($hitActorKeys as $actorKey) {
            if ($actorKey === '' || strlen($actorKey) > 96 || preg_match('//u', $actorKey) !== 1) {
                throw new InvalidArgumentException('Projectile hit identity is invalid.');
            }
        }
    }

    public function tick(): self
    {
        if ($this->state === ProjectileState::EMBEDDED) {
            return new self(
                $this->uniqueEntityId,
                $this->runtimeEntityId,
                $this->ownerUuid,
                $this->potionType,
                $this->lingering,
                $this->position,
                new EntityMotion(0.0, 0.0, 0.0),
                $this->ageTicks + 1,
                $this->tippedArrow,
                $this->pickupAllowed,
                $this->damageBonus,
                $this->knockbackStrength,
                $this->fireTicks,
                $this->type,
                $this->piercingRemaining,
                $this->hitActorKeys,
                $this->loyaltyLevel,
                $this->channeling,
                $this->carriedItem,
                $this->ownerRuntimeEntityId,
                $this->ownerType,
                $this->fishingBobbing,
                $this->fishingWaitTicks,
                $this->fishingBiteTicks,
                $this->fishingLuckLevel,
                $this->fishingLureLevel,
                $this->state,
                $this->yaw,
                $this->pitch,
                $this->embeddedBlock,
                $this->embeddedFace,
                $this->embeddedTicks + 1,
                $this->pickupMode,
            );
        }
        if ($this->state === ProjectileState::RETURNING) {
            return new self(
                $this->uniqueEntityId,
                $this->runtimeEntityId,
                $this->ownerUuid,
                $this->potionType,
                $this->lingering,
                new Position(
                    $this->position->x + $this->motion->x,
                    $this->position->y + $this->motion->y,
                    $this->position->z + $this->motion->z,
                ),
                $this->motion,
                $this->ageTicks + 1,
                $this->tippedArrow,
                $this->pickupAllowed,
                $this->damageBonus,
                $this->knockbackStrength,
                $this->fireTicks,
                $this->type,
                $this->piercingRemaining,
                $this->hitActorKeys,
                $this->loyaltyLevel,
                $this->channeling,
                $this->carriedItem,
                $this->ownerRuntimeEntityId,
                $this->ownerType,
                false,
                0,
                0,
                0,
                0,
                $this->state,
                self::rotation($this->motion)[1],
                self::rotation($this->motion)[0],
                null,
                null,
                0,
                $this->pickupMode,
            );
        }
        $friction = 1.0 - $this->type->drag();
        $gravity = $this->type === ProjectileType::FISHING_HOOK && $this->fishingBobbing
            ? 0.0
            : $this->type->gravity();
        $motion = new EntityMotion(
            $this->motion->x * $friction,
            ($this->motion->y * $friction) - $gravity,
            $this->motion->z * $friction,
        );
        $waitTicks = $this->fishingWaitTicks;
        $biteTicks = $this->fishingBiteTicks;
        if ($this->fishingBobbing) {
            if ($biteTicks > 0) {
                --$biteTicks;
                if ($biteTicks === 0) {
                    $waitTicks = $this->nextFishingWaitTicks($this->ageTicks + 1);
                }
            } elseif ($waitTicks > 0) {
                --$waitTicks;
                if ($waitTicks === 0) {
                    $biteTicks = 30;
                }
            }
        }

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            new Position(
                $this->position->x + $motion->x,
                $this->position->y + $motion->y,
                $this->position->z + $motion->z,
            ),
            $motion,
            $this->ageTicks + 1,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            $this->piercingRemaining,
            $this->hitActorKeys,
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            $this->fishingBobbing,
            $waitTicks,
            $biteTicks,
            $this->fishingLuckLevel,
            $this->fishingLureLevel,
            $this->state,
            self::rotation($motion)[1],
            self::rotation($motion)[0],
            $this->embeddedBlock,
            $this->embeddedFace,
            $this->embeddedTicks,
            $this->pickupMode,
        );
    }

    public function expired(): bool
    {
        return $this->ageTicks >= self::MAXIMUM_LIFETIME_TICKS
            && !($this->type === ProjectileType::TRIDENT
                && $this->loyaltyLevel > 0
                && $this->carriedItem !== null);
    }

    public function ownedByPlayer(string $uuid, int $runtimeEntityId): bool
    {
        return $this->ownerType === ProjectileOwnerType::PLAYER
            && $this->ownerUuid === $uuid
            && $this->ownerRuntimeEntityId === $runtimeEntityId;
    }

    public function ownedByEntity(string $uuid, int $runtimeEntityId): bool
    {
        return $this->ownerType === ProjectileOwnerType::ENTITY
            && $this->ownerUuid === $uuid
            && $this->ownerRuntimeEntityId === $runtimeEntityId;
    }

    public function atPosition(Position $position): self
    {
        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            $position,
            $this->motion,
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            $this->piercingRemaining,
            $this->hitActorKeys,
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            $this->fishingBobbing,
            $this->fishingWaitTicks,
            $this->fishingBiteTicks,
            $this->fishingLuckLevel,
            $this->fishingLureLevel,
            $this->state,
            $this->yaw,
            $this->pitch,
            $this->embeddedBlock,
            $this->embeddedFace,
            $this->embeddedTicks,
            $this->pickupMode,
        );
    }

    public function withMotion(EntityMotion $motion): self
    {
        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            $this->position,
            $motion,
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            $this->piercingRemaining,
            $this->hitActorKeys,
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            $this->fishingBobbing,
            $this->fishingWaitTicks,
            $this->fishingBiteTicks,
            $this->fishingLuckLevel,
            $this->fishingLureLevel,
            $this->state,
            self::rotation($motion)[1],
            self::rotation($motion)[0],
            $this->embeddedBlock,
            $this->embeddedFace,
            $this->embeddedTicks,
            $this->pickupMode,
        );
    }

    public function reflectedBy(string $ownerUuid, int $ownerRuntimeEntityId, EntityMotion $motion): self
    {
        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $ownerUuid,
            $this->potionType,
            $this->lingering,
            $this->position,
            $motion,
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            $this->piercingRemaining,
            [],
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $ownerRuntimeEntityId,
            ProjectileOwnerType::PLAYER,
            false,
            0,
            0,
            0,
            0,
            ProjectileState::FLYING,
            self::rotation($motion)[1],
            self::rotation($motion)[0],
            null,
            null,
            0,
            $this->pickupMode,
        );
    }

    public function afterPiercing(string $actorKey): self
    {
        if ($this->piercingRemaining < 1 || in_array($actorKey, $this->hitActorKeys, true)) {
            throw new InvalidArgumentException('Projectile cannot pierce the requested actor.');
        }

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            $this->position,
            $this->motion,
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            $this->piercingRemaining - 1,
            [...$this->hitActorKeys, $actorKey],
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            $this->fishingBobbing,
            $this->fishingWaitTicks,
            $this->fishingBiteTicks,
            $this->fishingLuckLevel,
            $this->fishingLureLevel,
            $this->state,
            $this->yaw,
            $this->pitch,
            $this->embeddedBlock,
            $this->embeddedFace,
            $this->embeddedTicks,
            $this->pickupMode,
        );
    }

    public function beginBobbing(Position $position): self
    {
        if ($this->type !== ProjectileType::FISHING_HOOK) {
            throw new InvalidArgumentException('Only a fishing hook can begin bobbing.');
        }

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            $position,
            new EntityMotion($this->motion->x * 0.3, 0.0, $this->motion->z * 0.3),
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            $this->piercingRemaining,
            $this->hitActorKeys,
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            true,
            $this->nextFishingWaitTicks($this->ageTicks),
            0,
            $this->fishingLuckLevel,
            $this->fishingLureLevel,
            $this->state,
            $this->yaw,
            $this->pitch,
            $this->embeddedBlock,
            $this->embeddedFace,
            $this->embeddedTicks,
            $this->pickupMode,
        );
    }

    public function bobAt(float $surfaceY): self
    {
        if (!$this->fishingBobbing || !is_finite($surfaceY)) {
            throw new InvalidArgumentException('Fishing hook bobbing state is invalid.');
        }
        $delta = ($surfaceY - 0.1) - $this->position->y;

        return (new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            $this->position,
            new EntityMotion(
                $this->motion->x * 0.9,
                ($this->motion->y * 0.7) + (max(-0.2, min(0.2, $delta)) * 0.2),
                $this->motion->z * 0.9,
            ),
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            $this->piercingRemaining,
            $this->hitActorKeys,
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            true,
            $this->fishingWaitTicks,
            $this->fishingBiteTicks,
            $this->fishingLuckLevel,
            $this->fishingLureLevel,
            $this->state,
            $this->yaw,
            $this->pitch,
            $this->embeddedBlock,
            $this->embeddedFace,
            $this->embeddedTicks,
            $this->pickupMode,
        ));
    }

    public function embeddedAt(Position $position, BlockPosition $block, BlockFace $face): self
    {
        if (!in_array($this->type, [ProjectileType::ARROW, ProjectileType::TRIDENT], true)
            || $this->state !== ProjectileState::FLYING) {
            throw new InvalidArgumentException('Only a flying arrow or trident can become embedded.');
        }

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            $position,
            new EntityMotion(0.0, 0.0, 0.0),
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            $this->piercingRemaining,
            $this->hitActorKeys,
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            false,
            0,
            0,
            0,
            0,
            ProjectileState::EMBEDDED,
            $this->yaw,
            $this->pitch,
            $block,
            $face,
            0,
            $this->pickupMode,
        );
    }

    public function dislodge(): self
    {
        if ($this->state !== ProjectileState::EMBEDDED) {
            throw new InvalidArgumentException('Only an embedded projectile can be dislodged.');
        }

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            $this->position,
            new EntityMotion(0.0, -0.05, 0.0),
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            0,
            $this->hitActorKeys,
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            false,
            0,
            0,
            0,
            0,
            ProjectileState::FLYING,
            $this->yaw,
            $this->pitch,
            null,
            null,
            0,
            $this->pickupMode,
        );
    }

    public function beginReturning(): self
    {
        if ($this->type !== ProjectileType::TRIDENT || $this->loyaltyLevel < 1 || $this->carriedItem === null) {
            throw new InvalidArgumentException('Only a Loyalty trident can begin returning.');
        }

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->lingering,
            $this->position,
            new EntityMotion(0.0, 0.0, 0.0),
            $this->ageTicks,
            $this->tippedArrow,
            $this->pickupAllowed,
            $this->damageBonus,
            $this->knockbackStrength,
            $this->fireTicks,
            $this->type,
            0,
            $this->hitActorKeys,
            $this->loyaltyLevel,
            $this->channeling,
            $this->carriedItem,
            $this->ownerRuntimeEntityId,
            $this->ownerType,
            false,
            0,
            0,
            0,
            0,
            ProjectileState::RETURNING,
            $this->yaw,
            $this->pitch,
            null,
            null,
            0,
            ArrowPickupMode::ANY,
        );
    }

    public function returnToward(Position $target): self
    {
        if ($this->state !== ProjectileState::RETURNING || $this->loyaltyLevel < 1) {
            throw new InvalidArgumentException('Projectile is not a returning Loyalty trident.');
        }
        $dx = $target->x - $this->position->x;
        $dy = $target->y - $this->position->y;
        $dz = $target->z - $this->position->z;
        $distance = max(0.000_001, hypot(hypot($dx, $dz), $dy));
        $speed = 0.45 + (0.15 * $this->loyaltyLevel);

        return $this->withMotion(new EntityMotion(
            ($dx / $distance) * $speed,
            ($dy / $distance) * $speed,
            ($dz / $distance) * $speed,
        ));
    }

    public function fishingBiteActive(): bool
    {
        return $this->type === ProjectileType::FISHING_HOOK && $this->fishingBiteTicks > 0;
    }

    private function nextFishingWaitTicks(int $salt): int
    {
        $sample = (int) hexdec(substr(hash('sha256', $this->runtimeEntityId . ':' . $salt), 0, 6));

        return max(20, 100 + ($sample % 501) - (100 * $this->fishingLureLevel));
    }

    /** @return array{float, float} Pitch and yaw in degrees. */
    private static function rotation(EntityMotion $motion): array
    {
        $horizontal = hypot($motion->x, $motion->z);
        if ($horizontal <= 0.000_001 && abs($motion->y) <= 0.000_001) {
            return [0.0, 0.0];
        }

        return [
            rad2deg(atan2(-$motion->y, $horizontal)),
            rad2deg(atan2(-$motion->x, $motion->z)),
        ];
    }
}
