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

namespace Bedriox\Server\Entity\Vanilla\Nether;

use Bedriox\Api\Entity\Vanilla\Piglin;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;

final class PiglinEntity extends NetherAngerableEntity implements Piglin
{
    public const int BARTER_ADMIRATION_TICKS = 120;

    private int $admirationTicks = 0;

    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position, ?AiBehaviorDefinition $behavior = null, EntityMotion $motion = new EntityMotion(), float $yaw = 0.0, float $pitch = 0.0, ?float $health = null)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::piglin(), $worldName, $position, $behavior ?? VanillaAiBehaviors::piglin(), $motion, $yaw, $pitch, $health);
    }

    public function isAdmiring(): bool
    {
        return $this->admirationTicks > 0;
    }

    public function beginAdmiring(): void
    {
        $this->admirationTicks = self::BARTER_ADMIRATION_TICKS;
        $memory = $this->aiRuntime()->memory();
        $memory->forget(VanillaAiMemories::nearestPlayer());
        $memory->forget(VanillaAiMemories::meleeIntent());
        $this->setMotion(new EntityMotion());
        $this->markPresentationChanged();
    }

    /** Returns true exactly once when the active admiration cycle completes. */
    public function advanceAdmiration(): bool
    {
        if ($this->admirationTicks === 0) {
            return false;
        }
        --$this->admirationTicks;
        $this->markChanged();

        return $this->admirationTicks === 0;
    }

    protected function additionalPersistenceData(): array
    {
        return ['admirationTicks' => $this->admirationTicks];
    }

    protected function restoreAdditionalPersistenceData(array $data): void
    {
        $ticks = $data['admirationTicks'] ?? null;
        if (!is_int($ticks) || $ticks < 0 || $ticks > self::BARTER_ADMIRATION_TICKS) {
            throw new \InvalidArgumentException('Persisted piglin admiration state is malformed.');
        }
        $this->admirationTicks = $ticks;
    }
}
