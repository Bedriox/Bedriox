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
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class ArmadilloEntity extends LandBreedableAnimalEntity implements Armadillo
{
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
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::armadillo(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('armadillo', ['minecraft:spider_eye'], 0.09), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
        if ($scuteShedTicks === 0) {
            $scuteShedTicks = 6_000 + ($runtimeId % 6_001);
        }
        self::validateScuteShedTicks($scuteShedTicks);
        $this->scuteShedTicks = $scuteShedTicks;
    }

    public function getState(): ArmadilloState
    {
        return $this->state;
    }

    public function getScuteShedTicks(): int
    {
        return $this->scuteShedTicks;
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

    protected function speciesPersistenceVariant(): string
    {
        return $this->state->value;
    }

    /** @return array{scuteShedTicks: int} */
    protected function speciesPersistenceData(): array
    {
        return ['scuteShedTicks' => $this->scuteShedTicks];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_string($variant) || ($state = ArmadilloState::tryFrom($variant)) === null
            || !is_int($data['scuteShedTicks'])) {
            throw new InvalidArgumentException('Persisted armadillo state is unsupported.');
        }
        self::validateScuteShedTicks($data['scuteShedTicks']);
        $this->state = $state;
        $this->scuteShedTicks = $data['scuteShedTicks'];
    }

    private static function validateScuteShedTicks(int $ticks): void
    {
        if ($ticks < 1 || $ticks > 12_000) {
            throw new InvalidArgumentException('Armadillo scute timer is outside its supported bounds.');
        }
    }
}
