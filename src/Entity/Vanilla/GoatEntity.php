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

use Bedriox\Api\Entity\Vanilla\Goat;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class GoatEntity extends LandBreedableAnimalEntity implements Goat
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
        private bool $screaming = false,
        private bool $leftHorn = true,
        private bool $rightHorn = true,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::goat(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('goat', ['minecraft:wheat'], 0.12), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
    }

    public function isScreaming(): bool
    {
        return $this->screaming;
    }

    public function hasLeftHorn(): bool
    {
        return $this->leftHorn;
    }

    public function hasRightHorn(): bool
    {
        return $this->rightHorn;
    }

    /** @internal Authoritative species-state mutation. */
    public function setHorns(bool $left, bool $right): void
    {
        if ($this->leftHorn !== $left || $this->rightHorn !== $right) {
            $this->leftHorn = $left;
            $this->rightHorn = $right;
            $this->markPresentationChanged();
        }
    }

    /** @return array{screaming: bool, leftHorn: bool, rightHorn: bool} */
    protected function speciesPersistenceData(): array
    {
        return [
            'screaming' => $this->screaming,
            'leftHorn' => $this->leftHorn,
            'rightHorn' => $this->rightHorn,
        ];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if ($variant !== null || !is_bool($data['screaming']) || !is_bool($data['leftHorn']) || !is_bool($data['rightHorn'])) {
            throw new InvalidArgumentException('Persisted goat state is malformed.');
        }
        $this->screaming = $data['screaming'];
        $this->leftHorn = $data['leftHorn'];
        $this->rightHorn = $data['rightHorn'];
    }
}
