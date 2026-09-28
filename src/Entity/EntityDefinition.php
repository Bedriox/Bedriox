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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityType;
use InvalidArgumentException;

/** Immutable shared definition; mutable per-entity state is deliberately kept elsewhere. */
final readonly class EntityDefinition
{
    public function __construct(
        public EntityType $type,
        public EntityCategory $category,
        public string $networkIdentifier,
        public float $width,
        public float $height,
        public float $maximumHealth,
        public float $gravity = 0.08,
        public float $drag = 0.02,
        public bool $persistent = true,
        public bool $burnsInDaylight = false,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $networkIdentifier) !== 1
            || strlen($networkIdentifier) > 128) {
            throw new InvalidArgumentException('Entity network identifier must be canonical, namespaced, and bounded.');
        }
        if (!is_finite($width) || $width <= 0.0 || $width > 64.0
            || !is_finite($height) || $height <= 0.0 || $height > 64.0) {
            throw new InvalidArgumentException('Entity dimensions are outside their supported bounds.');
        }
        if (!is_finite($maximumHealth) || $maximumHealth <= 0.0 || $maximumHealth > 1_000_000.0) {
            throw new InvalidArgumentException('Entity maximum health is outside its supported bounds.');
        }
        if (!is_finite($gravity) || $gravity < 0.0 || $gravity > 10.0
            || !is_finite($drag) || $drag < 0.0 || $drag >= 1.0) {
            throw new InvalidArgumentException('Entity physics values are outside their supported bounds.');
        }
    }
}
