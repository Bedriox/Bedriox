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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\Value\ArmadilloState;
use Bedriox\Api\Entity\Vanilla\Armadillo;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\ExclusiveAiActivity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class ArmadilloEntity extends LandBreedableAnimalEntity implements Armadillo, ExclusiveAiActivity
{
    public const int BRUSH_COOLDOWN_TICKS = 10;

    private int $brushCooldownUntilTick = 0;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        ?AiBehaviorDefinition $behavior = null,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
        bool $baby = false,
        private ArmadilloState $state = ArmadilloState::UNROLLED,
        private int $scuteShedTicks = 0,
        private int $defensiveTicks = 0,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::armadillo(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('armadillo', ['minecraft:spider_eye'], 0.09), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
        if ($scuteShedTicks === 0) {
            $scuteShedTicks = 6_000 + ($runtimeId % 6_001);
        }
        self::validateScuteShedTicks($scuteShedTicks);
        $this->scuteShedTicks = $scuteShedTicks;
        if ($defensiveTicks < 0 || $defensiveTicks > 200) {
            throw new InvalidArgumentException('Armadillo defensive timer is outside its supported bounds.');
        }
        $this->defensiveTicks = $defensiveTicks;
    }

    public function getState(): ArmadilloState
    {
        return $this->state;
    }

    public function hasExclusiveAiActivity(): bool
    {
        return $this->state !== ArmadilloState::UNROLLED;
    }

    public function getScuteShedTicks(): int
    {
        return $this->scuteShedTicks;
    }

    /** @internal Authoritative interaction admission. */
    public function canBeBrushed(int $currentTick): bool
    {
        if ($currentTick < 0) {
            throw new InvalidArgumentException('Armadillo brush tick cannot be negative.');
        }

        return !$this->isBaby() && $currentTick >= $this->brushCooldownUntilTick;
    }

    /** @internal Records one committed brush interaction. */
    public function recordBrushed(int $currentTick): void
    {
        if (!$this->canBeBrushed($currentTick)) {
            throw new InvalidArgumentException('Armadillo cannot be brushed during its current state.');
        }
        $this->brushCooldownUntilTick = min(PHP_INT_MAX, $currentTick + self::BRUSH_COOLDOWN_TICKS);
    }

    /** @internal Authoritative species timer. */
    public function advanceScuteShedTimer(int $ticks): bool
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Armadillo scute timer advance is outside its supported bound.');
        }
        $this->scuteShedTicks = max(0, $this->scuteShedTicks - $ticks);
        $this->markChanged();

        return $this->scuteShedTicks === 0;
    }

    /** @internal Authoritative species timer reset. */
    public function resetScuteShedTimer(int $ticks): void
    {
        self::validateScuteShedTicks($ticks);
        $this->scuteShedTicks = $ticks;
        $this->markChanged();
    }

    /** @internal Authoritative species-state mutation. */
    public function setState(ArmadilloState $state): void
    {
        if ($this->state !== $state) {
            $this->state = $state;
            $this->markPresentationChanged();
        }
    }

    /** @internal Advances the bounded threat-driven roll-up sequence. */
    public function advanceDefensiveState(bool $threatened, int $ticks, int $currentTick): void
    {
        if ($ticks < 1 || $ticks > 20 || $currentTick < 0) {
            throw new InvalidArgumentException('Armadillo defensive-state advance is outside its supported bound.');
        }
        if ($threatened) {
            $this->defensiveTicks = 200;
            $this->setState(ArmadilloState::ROLLED_UP);
            $motion = $this->getMotion();
            $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
            $this->suppressAiMovementUntil($currentTick + $ticks);
            $this->markChanged();
            return;
        }
        if ($this->defensiveTicks > 0) {
            $this->defensiveTicks = max(0, $this->defensiveTicks - $ticks);
            $this->setState($this->defensiveTicks > 60
                ? ArmadilloState::ROLLED_UP
                : ArmadilloState::ROLLED_UP_PEEKING);
            $this->markChanged();
            return;
        }
        if ($this->state !== ArmadilloState::UNROLLED) {
            $this->setState($this->state === ArmadilloState::ROLLED_UP_UNROLLING
                ? ArmadilloState::UNROLLED
                : ArmadilloState::ROLLED_UP_UNROLLING);
        }
    }

    protected function speciesPersistenceVariant(): string
    {
        return $this->state->value;
    }

    /** @return array{scuteShedTicks: int, defensiveTicks: int} */
    protected function speciesPersistenceData(): array
    {
        return ['scuteShedTicks' => $this->scuteShedTicks, 'defensiveTicks' => $this->defensiveTicks];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_string($variant) || ($state = ArmadilloState::tryFrom($variant)) === null
            || !is_int($data['scuteShedTicks']) || !is_int($data['defensiveTicks'])) {
            throw new InvalidArgumentException('Persisted armadillo state is unsupported.');
        }
        self::validateScuteShedTicks($data['scuteShedTicks']);
        if ($data['defensiveTicks'] < 0 || $data['defensiveTicks'] > 200) {
            throw new InvalidArgumentException('Persisted armadillo defensive timer is malformed.');
        }
        $this->state = $state;
        $this->scuteShedTicks = $data['scuteShedTicks'];
        $this->defensiveTicks = $data['defensiveTicks'];
    }

    private static function validateScuteShedTicks(int $ticks): void
    {
        if ($ticks < 1 || $ticks > 12_000) {
            throw new InvalidArgumentException('Armadillo scute timer is outside its supported bounds.');
        }
    }
}
