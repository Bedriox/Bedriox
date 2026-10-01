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

use Bedriox\Api\Entity\Value\MooshroomVariant;
use Bedriox\Api\Entity\Vanilla\Mooshroom;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class MooshroomEntity extends LandBreedableAnimalEntity implements Mooshroom
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
        private MooshroomVariant $variant = MooshroomVariant::RED,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::mooshroom(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('mooshroom', ['minecraft:wheat'], 0.09), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
    }

    public function getVariant(): MooshroomVariant
    {
        return $this->variant;
    }

    /** @internal Authoritative species-state mutation. */
    public function setVariant(MooshroomVariant $variant): void
    {
        if ($this->variant !== $variant) {
            $this->variant = $variant;
            $this->markPresentationChanged();
        }
    }

    protected function speciesPersistenceVariant(): int
    {
        return $this->variant->value;
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_int($variant) || ($mooshroomVariant = MooshroomVariant::tryFrom($variant)) === null) {
            throw new InvalidArgumentException('Persisted mooshroom variant is unsupported.');
        }
        $this->variant = $mooshroomVariant;
    }
}
