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

use Bedriox\Api\Entity\Value\PandaActivity;
use Bedriox\Api\Entity\Value\PandaGene;
use Bedriox\Api\Entity\Vanilla\Panda;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\ExclusiveAiActivity;
use Bedriox\Server\Entity\Concern\AngerStateTrait;
use Bedriox\Server\Entity\Concern\MutableAngerState;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class PandaEntity extends LandBreedableAnimalEntity implements ExclusiveAiActivity, MutableAngerState, Panda
{
    use AngerStateTrait;

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
        private PandaGene $mainGene = PandaGene::NORMAL,
        private PandaGene $hiddenGene = PandaGene::NORMAL,
        private PandaActivity $activity = PandaActivity::IDLE,
        private int $activityTicks = 0,
        ?string $angerTargetUniqueId = null,
        int $angerTicks = 0,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::panda(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::panda(), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
        $this->initializeAngerState($angerTargetUniqueId, $angerTicks);
        $this->clampPersonalityHealth();
        if ($activityTicks < 0 || $activityTicks > 400) {
            throw new InvalidArgumentException('Panda activity timer is outside its supported bounds.');
        }
        $this->activityTicks = $activityTicks;
    }

    public function getMainGene(): PandaGene
    {
        return $this->mainGene;
    }

    public function getHiddenGene(): PandaGene
    {
        return $this->hiddenGene;
    }

    public function getExpressedGene(): PandaGene
    {
        if (in_array($this->mainGene, [PandaGene::BROWN, PandaGene::WEAK], true)
            && $this->hiddenGene !== $this->mainGene) {
            return PandaGene::NORMAL;
        }

        return $this->mainGene;
    }

    public function getActivity(): PandaActivity
    {
        return $this->activity;
    }

    public function hasExclusiveAiActivity(): bool
    {
        return $this->activity !== PandaActivity::IDLE;
    }

    /** @internal Authoritative behavior-state mutation. */
    public function setActivity(PandaActivity $activity): void
    {
        if ($this->activity !== $activity) {
            $this->activity = $activity;
            $this->markPresentationChanged();
        }
    }

    /** @internal Starts the bounded bamboo-eating presentation after authoritative item admission. */
    public function beginEating(int $currentTick): void
    {
        if ($currentTick < 0) {
            throw new InvalidArgumentException('Panda eating tick cannot be negative.');
        }
        $this->activityTicks = 60;
        $this->setActivity(PandaActivity::EATING);
        $motion = $this->getMotion();
        $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
        $this->suppressAiMovementUntil($currentTick + 20);
        $this->markChanged();
    }

    /** @internal Advances trait-specific bounded idle activity. */
    public function advanceTraitActivity(bool $thundering, int $ticks, int $currentTick): void
    {
        if ($ticks < 1 || $ticks > 20 || $currentTick < 0) {
            throw new InvalidArgumentException('Panda activity advance is outside its supported bounds.');
        }
        if ($this->activityTicks > 0) {
            $this->activityTicks = max(0, $this->activityTicks - $ticks);
            if ($this->activityTicks === 0) {
                $this->setActivity(PandaActivity::IDLE);
            } else {
                $this->suppressAiMovementUntil($currentTick + $ticks);
                $this->markChanged();
            }
            return;
        }
        $gene = $this->getExpressedGene();
        [$activity, $duration] = match (true) {
            $gene === PandaGene::WORRIED && $thundering => [PandaActivity::SCARED, 200],
            $gene === PandaGene::PLAYFUL && ($currentTick + $this->getRuntimeId()) % 160 === 0 => [PandaActivity::ROLLING, 40],
            $this->isBaby() && ($currentTick + $this->getRuntimeId()) % 600 === 0 => [PandaActivity::SNEEZING, 20],
            $gene === PandaGene::LAZY && ($currentTick + $this->getRuntimeId()) % 240 === 0 => [PandaActivity::SITTING, 100],
            default => [PandaActivity::IDLE, 0],
        };
        if ($duration > 0) {
            $this->activityTicks = $duration;
            $this->setActivity($activity);
            $motion = $this->getMotion();
            $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
            $this->suppressAiMovementUntil($currentTick + $ticks);
        }
    }

    /** @internal Authoritative species-state mutation. */
    public function setGenes(PandaGene $main, PandaGene $hidden): void
    {
        if ($this->mainGene !== $main || $this->hiddenGene !== $hidden) {
            $this->mainGene = $main;
            $this->hiddenGene = $hidden;
            $this->clampPersonalityHealth();
            $this->markPresentationChanged();
        }
    }

    protected function speciesPersistenceVariant(): int
    {
        return $this->mainGene->value;
    }

    /** @return array{hiddenGene: int, activity: string, activityTicks: int, angerTargetUniqueId: ?string, angerTicks: int} */
    protected function speciesPersistenceData(): array
    {
        return [
            'hiddenGene' => $this->hiddenGene->value,
            'activity' => $this->activity->value,
            'activityTicks' => $this->activityTicks,
            ...$this->angerPersistenceData(),
        ];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_int($variant) || !is_int($data['hiddenGene']) || !is_string($data['activity'])
            || !is_int($data['activityTicks']) || $data['activityTicks'] < 0 || $data['activityTicks'] > 400
            || ($mainGene = PandaGene::tryFrom($variant)) === null
            || ($hiddenGene = PandaGene::tryFrom($data['hiddenGene'])) === null
            || ($activity = PandaActivity::tryFrom($data['activity'])) === null) {
            throw new InvalidArgumentException('Persisted panda genes are malformed.');
        }
        $this->mainGene = $mainGene;
        $this->hiddenGene = $hiddenGene;
        $this->activity = $activity;
        $this->activityTicks = $data['activityTicks'];
        $this->restoreAngerPersistenceData($data);
        $this->clampPersonalityHealth();
    }

    protected function baseMaximumHealth(): float
    {
        return $this->getExpressedGene() === PandaGene::WEAK ? 10.0 : parent::baseMaximumHealth();
    }

    private function clampPersonalityHealth(): void
    {
        $excess = $this->getHealth() - $this->getMaximumHealth();
        if ($excess > 0.0) {
            $this->damage($excess);
        }
    }
}
