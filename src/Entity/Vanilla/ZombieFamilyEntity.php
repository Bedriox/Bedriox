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

use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\MonsterEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Shared immutable-age state for zombie-family species that support baby variants. */
abstract class ZombieFamilyEntity extends MonsterEntity
{
    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        Position $position,
        AiBehaviorDefinition $behavior,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
        private bool $baby = false,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            $definition,
            $worldName,
            $position,
            $behavior,
            $motion,
            $yaw,
            $pitch,
            $health,
        );
    }

    final public function isBaby(): bool
    {
        return $this->baby;
    }

    /** @internal */
    final public function setBaby(bool $baby): void
    {
        if ($this->baby !== $baby) {
            $this->baby = $baby;
            $this->markPresentationChanged();
        }
    }

    /** @return array{baby: bool} */
    final protected function zombieFamilyPersistenceData(): array
    {
        return ['baby' => $this->baby];
    }

    /** @param array<mixed, mixed> $data */
    final protected function restoreZombieFamilyPersistenceData(array $data): void
    {
        if (!isset($data['baby']) || !is_bool($data['baby'])) {
            throw new InvalidArgumentException('Persisted zombie-family state is malformed.');
        }
        $this->baby = $data['baby'];
    }

    protected function sizeMultiplier(): float
    {
        return $this->baby ? 0.5 : 1.0;
    }
}
