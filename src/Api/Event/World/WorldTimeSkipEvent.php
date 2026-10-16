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

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\World;
use InvalidArgumentException;

final class WorldTimeSkipEvent extends CancellableEvent
{
    public function __construct(
        public readonly World $world,
        private int $targetTime,
        public readonly WorldTimeSkipReason $reason,
    ) {
        self::validate($targetTime);
    }

    public function targetTime(): int
    {
        return $this->targetTime;
    }

    public function setTargetTime(int $targetTime): void
    {
        $this->assertMutable();
        self::validate($targetTime);
        $this->targetTime = $targetTime;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->targetTime];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid world time-skip event state.');
        }
        self::validate($state[1]);
        parent::replaceState($state[0]);
        $this->targetTime = $state[1];
    }

    private static function validate(int $targetTime): void
    {
        if ($targetTime < 0 || $targetTime > 0x7fffffff) {
            throw new InvalidArgumentException('World time must be between 0 and 2147483647 ticks.');
        }
    }
}
