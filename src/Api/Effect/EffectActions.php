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

use Closure;
use LogicException;

/** @internal Authoritative mutation path attached to a live effect snapshot. */
final readonly class EffectActions
{
    /**
     * @param Closure(EffectInstance, EffectCause): void $add
     * @param Closure(EffectType, EffectCause): void     $remove
     * @param Closure(EffectCause): void                 $clear
     */
    public function __construct(
        private Closure $add,
        private Closure $remove,
        private Closure $clear,
    ) {}

    public static function unavailable(): self
    {
        $unavailable = static function (): never {
            throw new LogicException('This effect snapshot is not attached to an authoritative runtime.');
        };

        return new self($unavailable, $unavailable, $unavailable);
    }

    public function add(EffectInstance $effect, EffectCause $cause): void
    {
        ($this->add)($effect, $cause);
    }

    public function remove(EffectType $type, EffectCause $cause): void
    {
        ($this->remove)($type, $cause);
    }

    public function clear(EffectCause $cause): void
    {
        ($this->clear)($cause);
    }
}
