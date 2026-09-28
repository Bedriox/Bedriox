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

namespace Bedriox\Server\Entity\Item;

use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** One immutable snapshot of a server-owned dropped item. */
final readonly class DroppedItemEntity
{
    public const int DEFAULT_DESPAWN_TICKS = 6_000;

    public function __construct(
        public int $uniqueEntityId,
        public int $runtimeEntityId,
        public InventoryStack $stack,
        public Position $position,
        public ItemEntityMotion $motion,
        public int $pickupDelayTicks = 0,
        public int $ageTicks = 0,
        public ?int $despawnAfterTicks = self::DEFAULT_DESPAWN_TICKS,
    ) {
        if ($uniqueEntityId < 1 || $runtimeEntityId < 1) {
            throw new InvalidArgumentException('Item-entity IDs must be positive.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0) {
            throw new InvalidArgumentException('Item-entity position must be finite and bounded.');
        }
        if ($pickupDelayTicks < 0 || $pickupDelayTicks > 0x7fffffff
            || $ageTicks < 0 || $ageTicks > 0x7fffffff
            || ($despawnAfterTicks !== null && ($despawnAfterTicks < 1 || $despawnAfterTicks > 0x7fffffff))) {
            throw new InvalidArgumentException('Item-entity lifetime values are outside their supported range.');
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

    public function tick(float $gravity, float $drag): self
    {
        $friction = 1.0 - $drag;
        $motion = new ItemEntityMotion(
            $this->motion->x * $friction,
            ($this->motion->y * $friction) - $gravity,
            $this->motion->z * $friction,
        );

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->stack,
            new Position(
                $this->position->x + $motion->x,
                $this->position->y + $motion->y,
                $this->position->z + $motion->z,
            ),
            $motion,
            max(0, $this->pickupDelayTicks - 1),
            $this->ageTicks + 1,
            $this->despawnAfterTicks,
        );
    }

    public function withStack(InventoryStack $stack): self
    {
        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $stack,
            $this->position,
            $this->motion,
            $this->pickupDelayTicks,
            $this->ageTicks,
            $this->despawnAfterTicks,
        );
    }

    public function withPositionAndMotion(Position $position, ItemEntityMotion $motion): self
    {
        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->stack,
            $position,
            $motion,
            $this->pickupDelayTicks,
            $this->ageTicks,
            $this->despawnAfterTicks,
        );
    }
}
