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

use Bedriox\Api\Entity\Value\MooshroomStewEffect;
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
        private ?MooshroomStewEffect $stewEffect = null,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::mooshroom(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('mooshroom', ['minecraft:wheat'], 0.09), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
    }

    public function getVariant(): MooshroomVariant
    {
        return $this->variant;
    }

    public function isSheared(): bool
    {
        return false;
    }

    public function getStewEffect(): ?MooshroomStewEffect
    {
        return $this->stewEffect;
    }

    /** @internal Records the one-shot stew effect owned by a brown mooshroom. */
    public function setStewEffect(?MooshroomStewEffect $effect): void
    {
        if ($this->variant !== MooshroomVariant::BROWN && $effect !== null) {
            throw new InvalidArgumentException('Only a brown mooshroom may retain a stew effect.');
        }
        if ($this->stewEffect !== $effect) {
            $this->stewEffect = $effect;
            $this->markChanged();
        }
    }

    /** @internal Consumes the current one-shot stew effect exactly once. */
    public function takeStewEffect(): ?MooshroomStewEffect
    {
        $effect = $this->stewEffect;
        if ($effect !== null) {
            $this->stewEffect = null;
            $this->markChanged();
        }

        return $effect;
    }

    /** @internal Applies vanilla lightning conversion between red and brown variants. */
    public function struckByLightning(): void
    {
        $this->setVariant($this->variant === MooshroomVariant::RED
            ? MooshroomVariant::BROWN
            : MooshroomVariant::RED);
    }

    /** @internal Authoritative species-state mutation. */
    public function setVariant(MooshroomVariant $variant): void
    {
        if ($this->variant !== $variant) {
            $this->variant = $variant;
            if ($variant !== MooshroomVariant::BROWN) {
                $this->stewEffect = null;
            }
            $this->markPresentationChanged();
        }
    }

    protected function speciesPersistenceVariant(): int
    {
        return $this->variant->value;
    }

    /** @return array{stewEffect: ?int} */
    protected function speciesPersistenceData(): array
    {
        return ['stewEffect' => $this->stewEffect?->value];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_int($variant) || ($mooshroomVariant = MooshroomVariant::tryFrom($variant)) === null
            || ($data['stewEffect'] !== null && !is_int($data['stewEffect']))) {
            throw new InvalidArgumentException('Persisted mooshroom variant is unsupported.');
        }
        $stewEffect = $data['stewEffect'] === null ? null : MooshroomStewEffect::tryFrom($data['stewEffect']);
        if (($data['stewEffect'] !== null && $stewEffect === null)
            || ($mooshroomVariant !== MooshroomVariant::BROWN && $stewEffect !== null)) {
            throw new InvalidArgumentException('Persisted mooshroom stew state is unsupported.');
        }
        $this->variant = $mooshroomVariant;
        $this->stewEffect = $stewEffect;
    }
}
