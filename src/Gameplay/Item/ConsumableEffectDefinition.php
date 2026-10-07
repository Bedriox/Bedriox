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

namespace Bedriox\Server\Gameplay\Item;

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use InvalidArgumentException;

/** Server-owned effect mutation performed after an authoritative item consumption. */
final readonly class ConsumableEffectDefinition
{
    /** @var list<EffectInstance> */
    public array $effects;

    /** @param array<mixed> $effects */
    private function __construct(
        array $effects,
        public EffectCause $cause,
        public bool $clearExisting = false,
        public bool $resolvePotionAuxiliaryValue = false,
        public bool $resolveSuspiciousStewAuxiliaryValue = false,
    ) {
        if (!array_is_list($effects)) {
            throw new InvalidArgumentException('Consumable effects must be a list.');
        }
        foreach ($effects as $effect) {
            if (!$effect instanceof EffectInstance) {
                throw new InvalidArgumentException('Consumable effect list contains an invalid value.');
            }
        }
        if ($effects === [] && !$clearExisting && !$resolvePotionAuxiliaryValue
            && !$resolveSuspiciousStewAuxiliaryValue) {
            throw new InvalidArgumentException('Consumable effect behavior must perform an effect mutation.');
        }
        if ($resolvePotionAuxiliaryValue && ($effects !== [] || $clearExisting || $cause !== EffectCause::POTION)) {
            throw new InvalidArgumentException('Potion auxiliary resolution must be the sole potion effect behavior.');
        }
        if ($resolveSuspiciousStewAuxiliaryValue
            && ($effects !== [] || $clearExisting || $resolvePotionAuxiliaryValue || $cause !== EffectCause::FOOD)) {
            throw new InvalidArgumentException('Suspicious-stew auxiliary resolution must be the sole food effect behavior.');
        }
        $this->effects = $effects;
    }

    public static function potion(): self
    {
        return new self([], EffectCause::POTION, resolvePotionAuxiliaryValue: true);
    }

    public static function milk(): self
    {
        return new self([], EffectCause::MILK, clearExisting: true);
    }

    public static function suspiciousStew(): self
    {
        return new self([], EffectCause::FOOD, resolveSuspiciousStewAuxiliaryValue: true);
    }

    /** @param list<EffectInstance> $effects */
    public static function food(array $effects): self
    {
        return new self($effects, EffectCause::FOOD);
    }
}
