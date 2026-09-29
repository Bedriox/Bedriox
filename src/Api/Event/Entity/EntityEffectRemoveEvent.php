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

/** Cancellable intent emitted before an effect is explicitly removed. */
final class EntityEffectRemoveEvent extends CancellableEvent
{
    public function __construct(
        public readonly LivingEntity|Player $entity,
        public readonly EffectInstance $effect,
        public readonly EffectCause $cause,
    ) {}

    protected function cancellationAllowed(): bool
    {
        return $this->cause !== EffectCause::EXPIRATION;
    }
}
