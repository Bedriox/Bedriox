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

namespace Bedriox\Api\Effect;

use InvalidArgumentException;

/** Immutable effect value expressed in simulation ticks. Amplifier zero is level one. */
final readonly class EffectInstance
{
    public const int MAXIMUM_DURATION_TICKS = 0x7fffffff;
    public const int MAXIMUM_AMPLIFIER = 255;

    public function __construct(
        public EffectType $type,
        public int $durationTicks,
        public int $amplifier = 0,
        public bool $visible = true,
        public bool $ambient = false,
        public bool $infinite = false,
    ) {
        if ($durationTicks < 0 || $durationTicks > self::MAXIMUM_DURATION_TICKS) {
            throw new InvalidArgumentException('Effect duration is outside the supported range.');
        }
        if ($amplifier < 0 || $amplifier > self::MAXIMUM_AMPLIFIER) {
            throw new InvalidArgumentException('Effect amplifier is outside the supported range.');
        }
    }

    public function level(): int
    {
        return $this->amplifier + 1;
    }

    public function remainingAfter(int $ticks): self
    {
        if ($ticks < 0) {
            throw new InvalidArgumentException('Elapsed effect ticks cannot be negative.');
        }
        if ($this->infinite) {
            return $this;
        }

        return new self(
            $this->type,
            max(0, $this->durationTicks - $ticks),
            $this->amplifier,
            $this->visible,
            $this->ambient,
            false,
        );
    }
}
