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

namespace Bedriox\Api\Event\Vehicle;

use Bedriox\Api\Entity\Vanilla\Boat;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable authoritative boat-control intent before motion is applied. */
final class VehicleControlEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $driver,
        public readonly Boat $vehicle,
        private float $forward,
        private float $strafe,
        private float $yaw,
        private bool $paddlingLeft,
        private bool $paddlingRight,
    ) {
        self::validateAxis($forward);
        self::validateAxis($strafe);
        self::validateYaw($yaw);
    }

    public function forward(): float
    {
        return $this->forward;
    }
    public function strafe(): float
    {
        return $this->strafe;
    }
    public function yaw(): float
    {
        return $this->yaw;
    }
    public function isPaddlingLeft(): bool
    {
        return $this->paddlingLeft;
    }
    public function isPaddlingRight(): bool
    {
        return $this->paddlingRight;
    }

    public function setAxes(float $forward, float $strafe): void
    {
        self::validateAxis($forward);
        self::validateAxis($strafe);
        $this->forward = $forward;
        $this->strafe = $strafe;
    }

    public function setYaw(float $yaw): void
    {
        self::validateYaw($yaw);
        $this->yaw = $yaw;
    }

    public function setPaddling(bool $left, bool $right): void
    {
        $this->paddlingLeft = $left;
        $this->paddlingRight = $right;
    }

    private static function validateAxis(float $value): void
    {
        if (!is_finite($value) || $value < -1.0 || $value > 1.0) {
            throw new InvalidArgumentException('Vehicle-control axes must be finite and normalized.');
        }
    }

    private static function validateYaw(float $yaw): void
    {
        if (!is_finite($yaw) || $yaw < 0.0 || $yaw >= 360.0) {
            throw new InvalidArgumentException('Vehicle-control yaw must be finite and normalized.');
        }
    }
}
