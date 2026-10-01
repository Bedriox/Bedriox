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

use Bedriox\Api\Entity\Vanilla\Sniffer;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class SnifferEntity extends LandBreedableAnimalEntity implements Sniffer
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
        private bool $digging = false,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::sniffer(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('sniffer', ['minecraft:torchflower_seeds'], 0.09), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
    }

    public function isDigging(): bool
    {
        return $this->digging;
    }

    /** @internal Authoritative species-state mutation. */
    public function setDigging(bool $digging): void
    {
        if ($this->digging !== $digging) {
            $this->digging = $digging;
            $this->markPresentationChanged();
        }
    }

    protected function babyScale(): float
    {
        return 0.45;
    }

    /** @return array{digging: bool} */
    protected function speciesPersistenceData(): array
    {
        return ['digging' => $this->digging];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if ($variant !== null || !is_bool($data['digging'])) {
            throw new InvalidArgumentException('Persisted sniffer state is malformed.');
        }
        $this->digging = $data['digging'];
    }
}
