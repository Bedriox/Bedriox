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

use Bedriox\Api\Entity\EntityHealthRegainCause;
use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

final class EntityRegainHealthEvent extends CancellableEvent
{
    public function __construct(public readonly LivingEntity $entity, public readonly EntityHealthRegainCause $cause, private float $amount)
    {
        self::validateAmount($amount);
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->assertMutable();
        self::validateAmount($amount);
        $this->amount = $amount;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->amount];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_float($state[1])) {
            throw new InvalidArgumentException('Invalid entity regain-health event state.');
        }
        parent::replaceState($state[0]);
        self::validateAmount($state[1]);
        $this->amount = $state[1];
    }

    private static function validateAmount(float $amount): void
    {
        if (!is_finite($amount) || $amount < 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException('Health restoration must be finite and bounded.');
        }
    }
}
