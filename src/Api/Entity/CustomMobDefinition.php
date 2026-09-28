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

namespace Bedriox\Api\Entity;

use Closure;
use InvalidArgumentException;

final readonly class CustomMobDefinition
{
    public const int DEFAULT_MAXIMUM_STATE_BYTES = 16_384;

    /** @var Closure(): mixed */
    public Closure $factory;

    /** @param callable(): CustomMobBehavior $factory */
    public function __construct(
        public CustomEntityType $type,
        public VanillaEntityIdentity $networkAppearance,
        public EntityCategory $category,
        public float $width,
        public float $height,
        public float $maximumHealth,
        callable $factory,
        public CustomMobStateCodec $stateCodec = new EmptyCustomMobStateCodec(),
        public int $maximumStateBytes = self::DEFAULT_MAXIMUM_STATE_BYTES,
        public bool $persistent = true,
    ) {
        if (!is_finite($width) || $width <= 0.0 || $width > 64.0
            || !is_finite($height) || $height <= 0.0 || $height > 64.0) {
            throw new InvalidArgumentException('Custom mob dimensions are outside their supported bounds.');
        }
        if (!is_finite($maximumHealth) || $maximumHealth <= 0.0 || $maximumHealth > 1_000_000.0) {
            throw new InvalidArgumentException('Custom mob maximum health is outside its supported bounds.');
        }
        if ($maximumStateBytes < 0 || $maximumStateBytes > CustomEntityState::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Custom mob state byte limit is outside its supported bounds.');
        }
        $this->factory = Closure::fromCallable($factory);
    }
}
