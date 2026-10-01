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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\Position;
use InvalidArgumentException;

/** Cancellable, adjustable intent before an entity explosion is planned. */
final class EntityExplosionPrimeEvent extends CancellableEvent
{
    public const float MAXIMUM_RADIUS = 16.0;

    public function __construct(
        public readonly Entity $entity,
        public readonly Position $position,
        private float $radius,
        private bool $breaksBlocks = true,
        private float $fireChance = 0.0,
    ) {
        $position->validate();
        self::validateRadius($radius);
        self::validateFireChance($fireChance);
    }

    public function radius(): float
    {
        return $this->radius;
    }

    public function setRadius(float $radius): void
    {
        $this->assertMutable();
        self::validateRadius($radius);
        $this->radius = $radius;
    }

    public function breaksBlocks(): bool
    {
        return $this->breaksBlocks;
    }

    public function setBreaksBlocks(bool $breaksBlocks): void
    {
        $this->assertMutable();
        $this->breaksBlocks = $breaksBlocks;
    }

    public function fireChance(): float
    {
        return $this->fireChance;
    }

    public function setFireChance(float $fireChance): void
    {
        $this->assertMutable();
        self::validateFireChance($fireChance);
        $this->fireChance = $fireChance;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->radius, $this->breaksBlocks, $this->fireChance];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 4 || !is_bool($state[0]) || !is_float($state[1])
            || !is_bool($state[2]) || !is_float($state[3])) {
            throw new InvalidArgumentException('Invalid entity explosion-prime event state.');
        }
        self::validateRadius($state[1]);
        self::validateFireChance($state[3]);
        parent::replaceState($state[0]);
        $this->radius = $state[1];
        $this->breaksBlocks = $state[2];
        $this->fireChance = $state[3];
    }

    private static function validateRadius(float $radius): void
    {
        if (!is_finite($radius) || $radius <= 0.0 || $radius > self::MAXIMUM_RADIUS) {
            throw new InvalidArgumentException('Explosion radius must be finite, positive, and at most 16 blocks.');
        }
    }

    private static function validateFireChance(float $fireChance): void
    {
        if (!is_finite($fireChance) || $fireChance < 0.0 || $fireChance > 1.0) {
            throw new InvalidArgumentException('Explosion fire chance must be between 0 and 1.');
        }
    }
}
