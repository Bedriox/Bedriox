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

/** Immutable authoritative splash or lingering potion projectile snapshot. */
final readonly class PotionProjectile
{
    public const float GRAVITY = 0.05;
    public const float DRAG = 0.01;
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
    ) {
        if ($uniqueEntityId < 1 || $uniqueEntityId >= PHP_INT_MAX
            || $runtimeEntityId < 1 || $runtimeEntityId >= PHP_INT_MAX || $ownerUuid === ''
            || strlen($ownerUuid) > 64 || preg_match('//u', $ownerUuid) !== 1) {
            throw new InvalidArgumentException('Potion projectile identity is invalid.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 30_000_000.0 || $ageTicks < 0 || $ageTicks > self::MAXIMUM_LIFETIME_TICKS) {
            throw new InvalidArgumentException('Potion projectile state is outside its supported bounds.');
        }
    }

    public function tick(): self
    {
        $friction = 1.0 - self::DRAG;
        $motion = new EntityMotion(
            $this->motion->x * $friction,
            ($this->motion->y * $friction) - self::GRAVITY,
            $this->motion->z * $friction,
        );

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
        );
    }

    public function expired(): bool
    {
        return $this->ageTicks >= self::MAXIMUM_LIFETIME_TICKS;
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
        );
    }
}
