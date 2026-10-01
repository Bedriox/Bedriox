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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\Capability\Breedable;
use InvalidArgumentException;

/** Shared authoritative age and breeding state for vanilla animals. */
abstract class BreedableAnimalEntity extends AnimalEntity implements Breedable
{
    public const int MAXIMUM_LOVE_TICKS = 600;
    public const int BABY_GROWTH_TICKS = 24_000;
    public const int BREEDING_COOLDOWN_TICKS = 6_000;

    private bool $baby;
    private int $loveTicks;
    private int $babyGrowthTicks;
    private int $breedingCooldownTicks;

    final protected function initializeBreedableState(
        bool $baby = false,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
    ): void {
        if ($baby && $babyGrowthTicks === 0) {
            $babyGrowthTicks = self::BABY_GROWTH_TICKS;
        }
        self::validateBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
        $this->baby = $baby;
        $this->loveTicks = $loveTicks;
        $this->babyGrowthTicks = $babyGrowthTicks;
        $this->breedingCooldownTicks = $breedingCooldownTicks;
    }

    final public function isBaby(): bool
    {
        return $this->baby;
    }

    final public function getLoveTicks(): int
    {
        return $this->loveTicks;
    }

    final public function isReadyToBreed(): bool
    {
        return !$this->baby && $this->loveTicks > 0 && $this->breedingCooldownTicks === 0;
    }

    final public function setBaby(bool $baby): void
    {
        if ($this->baby === $baby) {
            return;
        }
        $this->baby = $baby;
        $this->babyGrowthTicks = $baby ? self::BABY_GROWTH_TICKS : 0;
        if ($baby) {
            $this->loveTicks = 0;
        }
        $this->markPresentationChanged();
    }

    final public function setLoveTicks(int $ticks): void
    {
        self::validateBreedableState($this->baby, $ticks, $this->babyGrowthTicks, $this->breedingCooldownTicks);
        if ($this->loveTicks !== $ticks) {
            $this->loveTicks = $ticks;
            $this->markPresentationChanged();
        }
    }

    final public function advanceSpeciesState(int $ticks = 1): void
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Animal species-state advance is outside its supported bound.');
        }
        $changed = false;
        if ($this->loveTicks > 0) {
            $this->loveTicks = max(0, $this->loveTicks - $ticks);
            $changed = true;
        }
        if ($this->breedingCooldownTicks > 0) {
            $this->breedingCooldownTicks = max(0, $this->breedingCooldownTicks - $ticks);
            $changed = true;
        }
        if ($this->babyGrowthTicks > 0) {
            $this->babyGrowthTicks = max(0, $this->babyGrowthTicks - $ticks);
            $changed = true;
            if ($this->babyGrowthTicks === 0) {
                $this->baby = false;
                $this->markPresentationChanged();

                return;
            }
        }
        if ($changed) {
            $this->markChanged();
        }
    }

    final public function beginBreedingCooldown(): void
    {
        $this->loveTicks = 0;
        $this->breedingCooldownTicks = self::BREEDING_COOLDOWN_TICKS;
        $this->markPresentationChanged();
    }

    final public function accelerateGrowth(int $ticks): void
    {
        if (!$this->baby || $ticks < 1 || $ticks > self::BABY_GROWTH_TICKS) {
            throw new InvalidArgumentException('Animal growth acceleration is outside its supported bounds.');
        }
        $this->babyGrowthTicks = max(0, $this->babyGrowthTicks - $ticks);
        if ($this->babyGrowthTicks === 0) {
            $this->baby = false;
            $this->markPresentationChanged();

            return;
        }
        $this->markChanged();
    }

    /** @return array{baby: bool, babyGrowthTicks: int, breedingCooldownTicks: int, loveTicks: int} */
    final protected function breedablePersistenceData(): array
    {
        return [
            'baby' => $this->baby,
            'babyGrowthTicks' => $this->babyGrowthTicks,
            'breedingCooldownTicks' => $this->breedingCooldownTicks,
            'loveTicks' => $this->loveTicks,
        ];
    }

    /** @param array<mixed> $data */
    final protected function restoreBreedablePersistenceData(array $data): void
    {
        foreach (['baby', 'babyGrowthTicks', 'breedingCooldownTicks', 'loveTicks'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidArgumentException('Persisted animal state is missing breeding data.');
            }
        }
        if (!is_bool($data['baby']) || !is_int($data['babyGrowthTicks'])
            || !is_int($data['breedingCooldownTicks']) || !is_int($data['loveTicks'])) {
            throw new InvalidArgumentException('Persisted animal breeding data is malformed.');
        }
        self::validateBreedableState(
            $data['baby'],
            $data['loveTicks'],
            $data['babyGrowthTicks'],
            $data['breedingCooldownTicks'],
        );
        $this->baby = $data['baby'];
        $this->babyGrowthTicks = $data['babyGrowthTicks'];
        $this->breedingCooldownTicks = $data['breedingCooldownTicks'];
        $this->loveTicks = $data['loveTicks'];
    }

    final protected function sizeMultiplier(): float
    {
        return $this->baby ? $this->babyScale() : 1.0;
    }

    protected function babyScale(): float
    {
        return 0.5;
    }

    private static function validateBreedableState(
        bool $baby,
        int $loveTicks,
        int $babyGrowthTicks,
        int $breedingCooldownTicks,
    ): void {
        if ($loveTicks < 0 || $loveTicks > self::MAXIMUM_LOVE_TICKS || ($baby && $loveTicks !== 0)
            || $babyGrowthTicks < 0 || $babyGrowthTicks > self::BABY_GROWTH_TICKS
            || ($baby !== ($babyGrowthTicks > 0))
            || $breedingCooldownTicks < 0 || $breedingCooldownTicks > self::BREEDING_COOLDOWN_TICKS) {
            throw new InvalidArgumentException('Animal breeding state is outside its supported bounds.');
        }
    }
}
