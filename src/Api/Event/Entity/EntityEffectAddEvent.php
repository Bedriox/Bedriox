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

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable, adjustable intent emitted before an authoritative effect mutation. */
final class EntityEffectAddEvent extends CancellableEvent
{
    public function __construct(
        public readonly LivingEntity|Player $entity,
        private EffectInstance $effect,
        public readonly EffectCause $cause,
        public readonly ?EffectInstance $previous,
    ) {}

    public function effect(): EffectInstance
    {
        return $this->effect;
    }

    public function setEffect(EffectInstance $effect): void
    {
        $this->assertMutable();
        if ($effect->type !== $this->effect->type) {
            throw new InvalidArgumentException('An effect event cannot change the effect type.');
        }
        $this->effect = $effect;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->effect];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof EffectInstance) {
            throw new InvalidArgumentException('Invalid entity effect-add event state.');
        }
        parent::replaceState($state[0]);
        $this->effect = $state[1];
    }
}
