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

namespace Bedriox\Api\World;

use InvalidArgumentException;
use LogicException;

final readonly class Position
{
    public const float HORIZONTAL_LIMIT = 30_000_000.0;

    public function __construct(
        public float $x,
        public float $y,
        public float $z,
        public ?float $yaw = null,
        public ?float $pitch = null,
        public ?World $world = null,
    ) {}

    public function isResolved(): bool
    {
        return $this->world !== null && $this->yaw !== null && $this->pitch !== null;
    }

    /** Resolves omitted world and rotation values against an entity's current pose. */
    public function resolve(World $currentWorld, float $currentYaw, float $currentPitch): self
    {
        $resolved = new self(
            $this->x,
            $this->y,
            $this->z,
            $this->yaw ?? $currentYaw,
            $this->pitch ?? $currentPitch,
            $this->world ?? $currentWorld,
        );
        $resolved->validate();

        return $resolved;
    }

    /** Verifies a complete position before it enters authoritative world state. */
    public function validateResolved(): void
    {
        if (!$this->isResolved()) {
            throw new LogicException('Position must contain a world, yaw, and pitch.');
        }
        $this->validate();
    }

    /** Verifies bounded coordinates and any supplied rotation values. */
    public function validate(): void
    {
        if (!is_finite($this->x) || !is_finite($this->y) || !is_finite($this->z)
            || abs($this->x) > self::HORIZONTAL_LIMIT || abs($this->z) > self::HORIZONTAL_LIMIT) {
            throw new InvalidArgumentException('Position is outside the supported world boundary.');
        }
        if (($this->yaw !== null && !is_finite($this->yaw))
            || ($this->pitch !== null && (!is_finite($this->pitch) || $this->pitch < -90.0 || $this->pitch > 90.0))) {
            throw new InvalidArgumentException('Position rotation exceeds its accepted range.');
        }
    }
}
