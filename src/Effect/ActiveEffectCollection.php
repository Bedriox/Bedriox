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

namespace Bedriox\Server\Effect;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Closure;
use InvalidArgumentException;

/**
 * Authoritative active-effect owner with vanilla-compatible stronger-effect fallback.
 *
 * Hidden effects continue counting down. When a stronger effect expires, the strongest
 * surviving weaker effect becomes visible without losing its elapsed duration.
 *
 * @internal
 */
final class ActiveEffectCollection
{
    private const int MAXIMUM_HIDDEN_EFFECTS_PER_TYPE = 32;
    private const int MAXIMUM_TOTAL_HIDDEN_EFFECTS = 256;
    /** @var array<string, EffectInstance> */
    private array $active = [];

    /** @var array<string, list<EffectInstance>> */
    private array $hidden = [];

    /** @var array<string, int> */
    private array $infiniteElapsedTicks = [];

    /** @return array<string, EffectInstance> */
    public function snapshot(): array
    {
        return $this->active;
    }

    /** @internal Complete bounded state for persistence only. */
    public function persistenceState(): ActiveEffectPersistenceState
    {
        $phases = [];
        foreach ($this->active as $key => $effect) {
            $interval = VanillaEffectBehavior::periodicInterval($effect);
            if ($effect->infinite && $interval > 0) {
                $phases[$key] = ($this->infiniteElapsedTicks[$key] ?? 0) % $interval;
            }
        }

        return new ActiveEffectPersistenceState($this->active, $this->hidden, $phases);
    }

    public function get(EffectType $type): ?EffectInstance
    {
        return $this->active[$type->value] ?? null;
    }

    public function has(EffectType $type): bool
    {
        return isset($this->active[$type->value]);
    }

    public function add(EffectInstance $effect): ActiveEffectTransition
    {
        if ($effect->durationTicks === 0 && !$effect->infinite) {
            return new ActiveEffectTransition($this->get($effect->type), $this->get($effect->type), false);
        }

        $key = $effect->type->value;
        $current = $this->active[$key] ?? null;
        if ($current === null) {
            $this->active[$key] = $effect;
            $this->infiniteElapsedTicks[$key] = 0;

            return new ActiveEffectTransition(null, $effect, true);
        }

        if ($effect->amplifier > $current->amplifier) {
            if ($current->infinite || $current->durationTicks > $effect->durationTicks) {
                $this->mergeHidden($key, $current);
                $this->sortHidden($key);
            }
            $this->active[$key] = $effect;
            $this->infiniteElapsedTicks[$key] = 0;

            return new ActiveEffectTransition($current, $effect, true);
        }

        if ($effect->amplifier === $current->amplifier) {
            if (!$effect->infinite && ($current->infinite || $effect->durationTicks <= $current->durationTicks)) {
                return new ActiveEffectTransition($current, $current, false);
            }
            $this->active[$key] = $effect;
            $this->infiniteElapsedTicks[$key] = 0;

            return new ActiveEffectTransition($current, $effect, true);
        }

        if ($effect->infinite || (!$current->infinite && $effect->durationTicks > $current->durationTicks)) {
            $this->mergeHidden($key, $effect);
            $this->sortHidden($key);
        }

        return new ActiveEffectTransition($current, $current, false);
    }

    public function remove(EffectType $type): ActiveEffectTransition
    {
        $key = $type->value;
        $previous = $this->active[$key] ?? null;
        if ($previous === null) {
            return new ActiveEffectTransition(null, null, false);
        }
        unset($this->active[$key], $this->hidden[$key], $this->infiniteElapsedTicks[$key]);

        return new ActiveEffectTransition($previous, null, true);
    }

    /**
     * @param null|Closure(EffectInstance): bool $allowRemoval
     * @return list<ActiveEffectTransition>
     */
    public function clear(?Closure $allowRemoval = null): array
    {
        $transitions = [];
        foreach ($this->active as $key => $effect) {
            if ($allowRemoval !== null && !$allowRemoval($effect)) {
                continue;
            }
            $transitions[] = new ActiveEffectTransition($effect, null, true);
            unset($this->active[$key], $this->hidden[$key], $this->infiniteElapsedTicks[$key]);
        }

        return $transitions;
    }

    /**
     * Advances active and hidden durations and applies due periodic behavior.
     *
     * @param Closure(EffectInstance): void      $periodic
     * @param null|Closure(EffectInstance): void $beforeExpiration
     * @return list<ActiveEffectTransition>
     */
    public function tick(int $elapsedTicks, Closure $periodic, ?Closure $beforeExpiration = null): array
    {
        if ($elapsedTicks < 1 || $elapsedTicks > 1_200) {
            throw new InvalidArgumentException('Elapsed effect ticks must be between 1 and 1200.');
        }
        $transitions = [];
        foreach (array_keys($this->active) as $key) {
            $current = $this->active[$key];
            $applications = VanillaEffectBehavior::periodicApplications($current, $elapsedTicks);
            if ($current->infinite) {
                $before = $this->infiniteElapsedTicks[$key] ?? 0;
                $after = $before + $elapsedTicks;
                $interval = VanillaEffectBehavior::periodicInterval($current);
                $applications = $interval === 0 ? 0 : intdiv($after, $interval) - intdiv($before, $interval);
                $this->infiniteElapsedTicks[$key] = $after;
            }
            if ($applications > 0) {
                for ($i = 0; $i < $applications; ++$i) {
                    $periodic($current);
                }
            }
            $next = $current->remainingAfter($elapsedTicks);
            $hidden = [];
            foreach ($this->hidden[$key] ?? [] as $fallback) {
                $remaining = $fallback->remainingAfter($elapsedTicks);
                if ($remaining->infinite || $remaining->durationTicks > 0) {
                    $hidden[] = $remaining;
                }
            }
            $this->hidden[$key] = $hidden;
            $this->sortHidden($key);

            if ($next->infinite || $next->durationTicks > 0) {
                $this->active[$key] = $next;
                continue;
            }
            $beforeExpiration?->__invoke($current);
            $promoted = array_shift($this->hidden[$key]);
            if ($promoted === null) {
                unset($this->active[$key], $this->hidden[$key]);
                unset($this->infiniteElapsedTicks[$key]);
            } else {
                $this->active[$key] = $promoted;
                $this->infiniteElapsedTicks[$key] = 0;
            }
            $transitions[] = new ActiveEffectTransition($current, $promoted, true);
        }

        return $transitions;
    }

    /** @param list<EffectInstance> $effects */
    public function restore(array $effects): void
    {
        if ($this->active !== [] || $this->hidden !== []) {
            throw new InvalidArgumentException('Effects may only be restored into an empty collection.');
        }
        foreach ($effects as $effect) {
            $this->add($effect);
        }
    }

    /** @internal Restores a previously validated complete persistence state. */
    public function restorePersistenceState(ActiveEffectPersistenceState $state): void
    {
        if ($this->active !== [] || $this->hidden !== []) {
            throw new InvalidArgumentException('Effects may only be restored into an empty collection.');
        }
        foreach (array_keys($state->hidden + $state->infiniteElapsedTicks) as $key) {
            if (!isset($state->active[$key])) {
                throw new InvalidArgumentException('Persisted effect auxiliary state has no active owner.');
            }
        }
        if (array_sum(array_map(count(...), $state->hidden)) > self::MAXIMUM_TOTAL_HIDDEN_EFFECTS) {
            throw new InvalidArgumentException('Persisted hidden effect state exceeds its total limit.');
        }
        foreach ($state->active as $key => $effect) {
            if ($key !== $effect->type->value || (!$effect->infinite && $effect->durationTicks === 0)) {
                throw new InvalidArgumentException('Persisted active effect state is malformed.');
            }
            $this->active[$key] = $effect;
            $phase = $state->infiniteElapsedTicks[$key] ?? 0;
            $interval = VanillaEffectBehavior::periodicInterval($effect);
            if ($phase < 0
                || ((!$effect->infinite || $interval === 0) && $phase !== 0)
                || ($interval > 0 && $phase >= $interval)) {
                throw new InvalidArgumentException('Persisted infinite effect phase is malformed.');
            }
            $this->infiniteElapsedTicks[$key] = $phase;
            if (count($state->hidden[$key] ?? []) > self::MAXIMUM_HIDDEN_EFFECTS_PER_TYPE) {
                throw new InvalidArgumentException('Persisted hidden effect chain exceeds its per-type limit.');
            }
            $amplifiers = [];
            foreach ($state->hidden[$key] ?? [] as $fallback) {
                if ($fallback->type !== $effect->type || (!$fallback->infinite && $fallback->durationTicks === 0)) {
                    throw new InvalidArgumentException('Persisted hidden effect state is malformed.');
                }
                if (isset($amplifiers[$fallback->amplifier])) {
                    throw new InvalidArgumentException('Persisted hidden effect levels must be unique.');
                }
                $amplifiers[$fallback->amplifier] = true;
                $this->mergeHidden($key, $fallback);
            }
            $this->sortHidden($key);
        }
        $this->trimHiddenTotal();
    }

    private function sortHidden(string $key): void
    {
        if (!isset($this->hidden[$key])) {
            return;
        }
        usort($this->hidden[$key], static function (EffectInstance $left, EffectInstance $right): int {
            $amplifier = $right->amplifier <=> $left->amplifier;

            return $amplifier !== 0 ? $amplifier : $right->durationTicks <=> $left->durationTicks;
        });
        $this->hidden[$key] = array_slice(
            $this->hidden[$key],
            0,
            self::MAXIMUM_HIDDEN_EFFECTS_PER_TYPE,
        );
        $this->trimHiddenTotal();
    }

    private function mergeHidden(string $key, EffectInstance $effect): void
    {
        $merged = [];
        $found = false;
        foreach ($this->hidden[$key] ?? [] as $existing) {
            if ($existing->amplifier !== $effect->amplifier) {
                $merged[] = $existing;
                continue;
            }
            $found = true;
            if ($effect->infinite || (!$existing->infinite && $effect->durationTicks > $existing->durationTicks)) {
                $merged[] = $effect;
            } else {
                $merged[] = $existing;
            }
        }
        if (!$found) {
            $merged[] = $effect;
        }
        $this->hidden[$key] = $merged;
    }

    private function trimHiddenTotal(): void
    {
        $count = array_sum(array_map(count(...), $this->hidden));
        while ($count > self::MAXIMUM_TOTAL_HIDDEN_EFFECTS) {
            foreach (array_reverse(array_keys($this->hidden)) as $key) {
                if (($this->hidden[$key] ?? []) === []) {
                    continue;
                }
                array_pop($this->hidden[$key]);
                --$count;
                if ($count <= self::MAXIMUM_TOTAL_HIDDEN_EFFECTS) {
                    break;
                }
            }
        }
    }
}
