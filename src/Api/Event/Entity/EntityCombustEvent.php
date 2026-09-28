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

use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

/** Cancellable, adjustable ignition intent emitted before fire state changes. */
final class EntityCombustEvent extends CancellableEvent
{
    public const int MAXIMUM_DURATION_TICKS = 0x7fff;

    public function __construct(
        public readonly LivingEntity $entity,
        public readonly EntityCombustionCause $cause,
        private int $durationTicks,
    ) {
        self::validateDuration($durationTicks);
    }

    public function durationTicks(): int
    {
        return $this->durationTicks;
    }

    public function setDurationTicks(int $durationTicks): void
    {
        $this->assertMutable();
        self::validateDuration($durationTicks);
        $this->durationTicks = $durationTicks;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->durationTicks];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid entity combust event state.');
        }
        parent::replaceState($state[0]);
        $this->durationTicks = $state[1];
    }

    private static function validateDuration(int $durationTicks): void
    {
        if ($durationTicks < 0 || $durationTicks > self::MAXIMUM_DURATION_TICKS) {
            throw new InvalidArgumentException('Entity combustion duration is outside its supported bounds.');
        }
    }
}
