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
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::armadillo(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('armadillo', ['minecraft:spider_eye'], 0.09), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
    }

    public function getState(): ArmadilloState
    {
        return $this->state;
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

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_string($variant) || ($state = ArmadilloState::tryFrom($variant)) === null) {
            throw new InvalidArgumentException('Persisted armadillo state is unsupported.');
        }
        $this->state = $state;
    }
}
