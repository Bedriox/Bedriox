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

/** Immutable, persistence-safe snapshot of one world's current weather. */
final readonly class WeatherState
{
    public const int TICKS_PER_SECOND = 20;
    public const int MINIMUM_COMMAND_DURATION_SECONDS = 1;
    public const int MAXIMUM_COMMAND_DURATION_SECONDS = 1_000_000;
    public const int MAXIMUM_DURATION_TICKS = self::MAXIMUM_COMMAND_DURATION_SECONDS * self::TICKS_PER_SECOND;

    public function __construct(
        public WeatherType $type,
        public int $remainingTicks,
    ) {
        if ($remainingTicks < 0 || $remainingTicks > self::MAXIMUM_DURATION_TICKS) {
            throw new InvalidArgumentException('Weather duration must be between 0 and 20000000 ticks.');
        }
    }

    public static function fromCommandDuration(WeatherType $type, int $seconds): self
    {
        if ($seconds < self::MINIMUM_COMMAND_DURATION_SECONDS || $seconds > self::MAXIMUM_COMMAND_DURATION_SECONDS) {
            throw new InvalidArgumentException('Weather command duration must be between 1 and 1000000 seconds.');
        }

        return new self($type, $seconds * self::TICKS_PER_SECOND);
    }

    public function isRaining(): bool
    {
        return $this->type->hasPrecipitation();
    }

    public function isThundering(): bool
    {
        return $this->type->hasThunder();
    }

    /** Canonical Bedrock level.dat rainLevel projection. */
    public function rainLevel(): float
    {
        return $this->isRaining() ? 1.0 : 0.0;
    }

    /** Canonical Bedrock level.dat lightningLevel projection. */
    public function lightningLevel(): float
    {
        return $this->isThundering() ? 1.0 : 0.0;
    }

    public function withRemainingTicks(int $remainingTicks): self
    {
        return new self($this->type, $remainingTicks);
    }
}
