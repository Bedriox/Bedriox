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
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class PandaEntity extends LandBreedableAnimalEntity implements Panda
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
        private PandaGene $mainGene = PandaGene::NORMAL,
        private PandaGene $hiddenGene = PandaGene::NORMAL,
        private PandaActivity $activity = PandaActivity::IDLE,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::panda(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('panda', ['minecraft:bamboo'], 0.08), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
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

    /** @internal Authoritative behavior-state mutation. */
    public function setActivity(PandaActivity $activity): void
    {
        if ($this->activity !== $activity) {
            $this->activity = $activity;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative species-state mutation. */
    public function setGenes(PandaGene $main, PandaGene $hidden): void
    {
        if ($this->mainGene !== $main || $this->hiddenGene !== $hidden) {
            $this->mainGene = $main;
            $this->hiddenGene = $hidden;
            $this->markPresentationChanged();
        }
    }

    protected function speciesPersistenceVariant(): int
    {
        return $this->mainGene->value;
    }

    /** @return array{hiddenGene: int, activity: string} */
    protected function speciesPersistenceData(): array
    {
        return ['hiddenGene' => $this->hiddenGene->value, 'activity' => $this->activity->value];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_int($variant) || !is_int($data['hiddenGene']) || !is_string($data['activity'])
            || ($mainGene = PandaGene::tryFrom($variant)) === null
            || ($hiddenGene = PandaGene::tryFrom($data['hiddenGene'])) === null
            || ($activity = PandaActivity::tryFrom($data['activity'])) === null) {
            throw new InvalidArgumentException('Persisted panda genes are malformed.');
        }
        $this->mainGene = $mainGene;
        $this->hiddenGene = $hiddenGene;
        $this->activity = $activity;
    }
}
