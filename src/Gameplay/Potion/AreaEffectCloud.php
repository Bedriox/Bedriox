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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Immutable authoritative lingering-potion cloud state. */
final readonly class AreaEffectCloud
{
    public const int DEFAULT_DURATION_TICKS = 600;
    public const int APPLICATION_INTERVAL_TICKS = 10;
    public const int REAPPLICATION_DELAY_TICKS = 40;
    public const float DEFAULT_RADIUS = 3.0;
    public const float RADIUS_CHANGE_PER_TICK = -(self::DEFAULT_RADIUS / self::DEFAULT_DURATION_TICKS);
    public const int MAXIMUM_VICTIM_COOLDOWNS = 4_096;

    /** @var array<string, int> victim UUID to next eligible cloud age */
    public array $victimCooldowns;

    /** @param array<string, int> $victimCooldowns */
    public function __construct(
        public int $uniqueEntityId,
        public int $runtimeEntityId,
        public string $ownerUuid,
        public PotionType $potionType,
        public Position $position,
        public int $ageTicks = 0,
        public int $durationTicks = self::DEFAULT_DURATION_TICKS,
        public float $radius = self::DEFAULT_RADIUS,
        array $victimCooldowns = [],
    ) {
        if ($uniqueEntityId < 1 || $uniqueEntityId >= PHP_INT_MAX
            || $runtimeEntityId < 1 || $runtimeEntityId >= PHP_INT_MAX
            || $ownerUuid === '' || strlen($ownerUuid) > 64 || preg_match('//u', $ownerUuid) !== 1
            || $ageTicks < 0 || $durationTicks < 1 || $durationTicks > 72_000
            || !is_finite($radius) || $radius < 0.0 || $radius > 32.0
            || count($victimCooldowns) > self::MAXIMUM_VICTIM_COOLDOWNS) {
            throw new InvalidArgumentException('Area-effect cloud state is outside its supported bounds.');
        }
        foreach ($victimCooldowns as $uuid => $eligibleAt) {
            if ($uuid === '' || strlen($uuid) > 72 || preg_match('//u', $uuid) !== 1
                || $eligibleAt < 0 || $eligibleAt > 72_040) {
                throw new InvalidArgumentException('Area-effect cloud victim cooldown is invalid.');
            }
        }
        $this->victimCooldowns = $victimCooldowns;
    }

    public function tick(): self
    {
        $age = $this->ageTicks + 1;
        $cooldowns = array_filter(
            $this->victimCooldowns,
            static fn(int $eligibleAt): bool => $eligibleAt > $age,
        );

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->position,
            $age,
            $this->durationTicks,
            max(0.0, $this->radius + self::RADIUS_CHANGE_PER_TICK),
            $cooldowns,
        );
    }

    public function shouldApply(): bool
    {
        return $this->ageTicks >= self::APPLICATION_INTERVAL_TICKS
            && $this->ageTicks % self::APPLICATION_INTERVAL_TICKS === 0;
    }

    public function canAffect(string $uuid): bool
    {
        return ($this->victimCooldowns[$uuid] ?? 0) <= $this->ageTicks;
    }

    public function afterAffecting(string $uuid): self
    {
        $cooldowns = $this->victimCooldowns;
        $cooldowns[$uuid] = $this->ageTicks + self::REAPPLICATION_DELAY_TICKS;

        return new self(
            $this->uniqueEntityId,
            $this->runtimeEntityId,
            $this->ownerUuid,
            $this->potionType,
            $this->position,
            $this->ageTicks,
            $this->durationTicks,
            max(0.0, $this->radius - 0.5),
            $cooldowns,
        );
    }

    public function expired(): bool
    {
        return $this->ageTicks > $this->durationTicks || $this->radius < 0.5;
    }
}
