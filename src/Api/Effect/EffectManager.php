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

/** Immutable effect snapshot with generation-bound authoritative mutations. */
final readonly class EffectManager
{
    /** @param array<string, EffectInstance> $effects */
    public function __construct(
        private array $effects,
        private EffectActions $actions,
    ) {
        foreach ($effects as $identifier => $effect) {
            if ($identifier !== $effect->type->value) {
                throw new \InvalidArgumentException('Effect snapshots must be indexed by canonical effect identifier.');
            }
        }
    }

    /** @return array<string, EffectInstance> */
    public function all(): array
    {
        return $this->effects;
    }

    public function get(EffectType $type): ?EffectInstance
    {
        return $this->effects[$type->value] ?? null;
    }

    public function has(EffectType $type): bool
    {
        return isset($this->effects[$type->value]);
    }

    public function add(EffectInstance $effect, EffectCause $cause = EffectCause::PLUGIN): void
    {
        $this->actions->add($effect, $cause);
    }

    public function remove(EffectType $type, EffectCause $cause = EffectCause::PLUGIN): void
    {
        $this->actions->remove($type, $cause);
    }

    public function clear(EffectCause $cause = EffectCause::PLUGIN): void
    {
        $this->actions->clear($cause);
    }
}
