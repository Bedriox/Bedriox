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

namespace Bedriox\Server\Entity\Experience;

use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** One immutable snapshot of a server-owned experience orb actor. */
final readonly class ExperienceOrbEntity
{
    public const int DEFAULT_DESPAWN_TICKS = 6_000;

    public function __construct(
        public int $uniqueEntityId,
        public int $runtimeEntityId,
        public int $value,
        public Position $position,
        public ExperienceOrbMotion $motion = new ExperienceOrbMotion(),
        public int $orbCount = 1,
        public int $pickupDelayTicks = 0,
        public int $ageTicks = 0,
        public ?int $despawnAfterTicks = self::DEFAULT_DESPAWN_TICKS,
        public ?string $targetSessionId = null,
        public int $targetSearchDelayTicks = 0,
    ) {
        if ($uniqueEntityId < 1 || $runtimeEntityId < 1) {
            throw new InvalidArgumentException('Experience-orb IDs must be positive.');
        }
        if ($value < 1 || $value > 32_767 || $orbCount < 1 || $orbCount > 32_767) {
            throw new InvalidArgumentException('Experience-orb value and count must be between 1 and 32767.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0) {
            throw new InvalidArgumentException('Experience-orb position must be finite and bounded.');
        }
        if ($pickupDelayTicks < 0 || $pickupDelayTicks > 32_767
            || $ageTicks < 0 || $ageTicks > 0x7fffffff
            || ($despawnAfterTicks !== null && ($despawnAfterTicks < 1 || $despawnAfterTicks > 0x7fffffff))
            || $targetSearchDelayTicks < 0 || $targetSearchDelayTicks > 20) {
            throw new InvalidArgumentException('Experience-orb lifetime state is outside its supported range.');
        }
    }

    public function canBePickedUp(): bool
    {
        return $this->pickupDelayTicks === 0;
    }

    public function hasExpired(): bool
    {
        return $this->despawnAfterTicks !== null && $this->ageTicks >= $this->despawnAfterTicks;
    }

    public function withTarget(?string $sessionId, int $searchDelayTicks): self
    {
        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->value,
            $this->position,
            $this->motion,
            $this->orbCount,
            $this->pickupDelayTicks,
            $this->ageTicks,
            $this->despawnAfterTicks,
            $sessionId,
            $searchDelayTicks,
        );
    }

    public function advance(?ExperienceOrbTarget $target, float $gravity, float $drag, float $attractionRadius): self
    {
        $x = $this->motion->x;
        $y = $this->motion->y;
        $z = $this->motion->z;
        if ($target !== null) {
            $dx = $target->pickupPosition->x - $this->position->x;
            $dy = $target->pickupPosition->y - $this->position->y;
            $dz = $target->pickupPosition->z - $this->position->z;
            $distance = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz));
            if ($distance > 0.0 && $distance < $attractionRadius) {
                $strength = (1.0 - ($distance / $attractionRadius)) ** 2 * 0.1;
                $x += ($dx / $distance) * $strength;
                $y += ($dy / $distance) * $strength;
                $z += ($dz / $distance) * $strength;
            }
        }
        $friction = 1.0 - $drag;
        $motion = new ExperienceOrbMotion($x * $friction, ($y - $gravity) * $friction, $z * $friction);

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->value,
            new Position(
                $this->position->x + $motion->x,
                $this->position->y + $motion->y,
                $this->position->z + $motion->z,
            ),
            $motion,
            $this->orbCount,
            max(0, $this->pickupDelayTicks - 1),
            $this->ageTicks + 1,
            $this->despawnAfterTicks,
            $target?->sessionId,
            max(0, $this->targetSearchDelayTicks - 1),
        );
    }

    public function withPositionAndMotion(Position $position, ExperienceOrbMotion $motion): self
    {
        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->value,
            $position,
            $motion,
            $this->orbCount,
            $this->pickupDelayTicks,
            $this->ageTicks,
            $this->despawnAfterTicks,
            $this->targetSessionId,
            $this->targetSearchDelayTicks,
        );
    }

    public function withOrbCount(int $count, ?int $ageTicks = null): self
    {
        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->value,
            $this->position,
            $this->motion,
            $count,
            $this->pickupDelayTicks,
            $ageTicks ?? $this->ageTicks,
            $this->despawnAfterTicks,
            $this->targetSessionId,
            $this->targetSearchDelayTicks,
        );
    }
}
