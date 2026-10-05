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

namespace Bedriox\Server\Player;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Effect\ActiveEffectPersistenceState;
use Bedriox\Server\Effect\VanillaEffectBehavior;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Fully resolved authoritative state used consistently by play bootstrap and simulation admission. */
final readonly class PlayerBootstrap
{
    public function __construct(
        public PlayerIdentity $identity,
        public string $worldName,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public PlayerInventoryState $inventory,
        public int $firstPlayedAt,
        public int $lastPlayedAt,
        public string $gamemode = 'survival',
        public float $health = 20.0,
        public float $food = PlayerVitals::MAX_FOOD,
        public float $saturation = PlayerVitals::MAX_SATURATION,
        public float $exhaustion = 0.0,
        /** @var list<EffectInstance> */
        public array $effects = [],
        public float $absorption = 0.0,
        public int $airTicks = PlayerVitals::MAX_AIR_TICKS,
        public int $fireTicks = 0,
        public ?ActiveEffectPersistenceState $effectPersistenceState = null,
        public int $totalExperience = 0,
        public ?Position $spawnPoint = null,
        public WorldDimension $dimension = WorldDimension::OVERWORLD,
    ) {
        if ($this->worldName === '' || strlen($this->worldName) > 64
            || preg_match('//u', $this->worldName) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $this->worldName) === 1) {
            throw new InvalidArgumentException('Player world name must be valid non-control UTF-8 between 1 and 64 bytes.');
        }
        foreach (['x' => $this->position->x, 'y' => $this->position->y, 'z' => $this->position->z,
            'yaw' => $this->yaw, 'pitch' => $this->pitch] as $name => $value) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException("Player $name must be finite.");
            }
        }
        if (abs($this->position->x) > 30_000_000.0 || abs($this->position->z) > 30_000_000.0
            || $this->position->y < -64.0 || $this->position->y > 319.0) {
            throw new InvalidArgumentException('Player position exceeds the supported world boundary.');
        }
        if ($this->yaw < -360.0 || $this->yaw > 360.0 || $this->pitch < -90.0 || $this->pitch > 90.0) {
            throw new InvalidArgumentException('Player orientation exceeds its accepted range.');
        }
        if ($this->firstPlayedAt < 0 || $this->lastPlayedAt < $this->firstPlayedAt) {
            throw new InvalidArgumentException('Player timestamps are invalid.');
        }
        if (GameMode::tryFrom($this->gamemode) === null) {
            throw new InvalidArgumentException('Player gamemode is unsupported.');
        }
        if (!is_finite($this->health) || $this->health < 0.0
            || $this->health > VanillaEffectBehavior::maximumHealth(array_combine(
                array_map(static fn(EffectInstance $effect): string => $effect->type->value, $this->effects),
                $this->effects,
            ) ?: [])) {
            throw new InvalidArgumentException('Player health must be finite and inside its authoritative range.');
        }
        if (!is_finite($this->food) || $this->food < 0.0 || $this->food > PlayerVitals::MAX_FOOD
            || !is_finite($this->saturation) || $this->saturation < 0.0
            || $this->saturation > PlayerVitals::MAX_SATURATION
            || !is_finite($this->exhaustion) || $this->exhaustion < 0.0
            || $this->exhaustion >= PlayerVitals::EXHAUSTION_THRESHOLD) {
            throw new InvalidArgumentException('Player nutrition is outside its authoritative range.');
        }
        $seenEffects = [];
        foreach ($this->effects as $effect) {
            if (isset($seenEffects[$effect->type->value]) || (!$effect->infinite && $effect->durationTicks === 0)) {
                throw new InvalidArgumentException('Persisted player effects must be unique and active.');
            }
            $seenEffects[$effect->type->value] = true;
        }
        if ($this->effectPersistenceState !== null
            && array_values($this->effectPersistenceState->active) !== $this->effects) {
            throw new InvalidArgumentException('Persisted complete effect state does not match its active effect view.');
        }
        if ($this->effectPersistenceState !== null) {
            (new \Bedriox\Server\Effect\ActiveEffectCollection())->restorePersistenceState(
                $this->effectPersistenceState,
            );
        }
        if (!is_finite($this->absorption) || $this->absorption < 0.0
            || $this->absorption > VanillaEffectBehavior::absorptionCapacity($seenEffects === [] ? [] : array_combine(
                array_map(static fn(EffectInstance $effect): string => $effect->type->value, $this->effects),
                $this->effects,
            ))) {
            throw new InvalidArgumentException('Player absorption exceeds the active effect capacity.');
        }
        if ($this->airTicks < -20 || $this->airTicks > PlayerVitals::MAX_AIR_TICKS
            || $this->fireTicks < 0 || $this->fireTicks > PlayerVitals::MAX_FIRE_TICKS) {
            throw new InvalidArgumentException('Player air or fire ticks are outside their authoritative range.');
        }
        if ($this->totalExperience < 0 || $this->totalExperience > ExperienceMath::MAXIMUM_TOTAL_POINTS) {
            throw new InvalidArgumentException('Player experience is outside its authoritative range.');
        }
        if ($this->spawnPoint !== null && (
            !is_finite($this->spawnPoint->x)
            || !is_finite($this->spawnPoint->y)
            || !is_finite($this->spawnPoint->z)
            || abs($this->spawnPoint->x) > 30_000_000.0
            || abs($this->spawnPoint->z) > 30_000_000.0
            || $this->spawnPoint->y < -64.0
            || $this->spawnPoint->y > 319.0
        )) {
            throw new InvalidArgumentException('Player spawn point exceeds the supported world boundary.');
        }
    }
}
