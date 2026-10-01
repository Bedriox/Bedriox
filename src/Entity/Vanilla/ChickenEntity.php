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

use Bedriox\Api\Entity\Controller\BreedableAnimalController;
use Bedriox\Api\Entity\Vanilla\Chicken;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Plugin\BufferedBreedableAnimalController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class ChickenEntity extends BreedableAnimalEntity implements Chicken, IntrinsicEntityPersistence
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
        private int $eggLayTicks = 0,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::chicken(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::chicken(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
        if ($eggLayTicks === 0) {
            $this->eggLayTicks = 6_000 + ($runtimeId % 6_001);
        } elseif ($eggLayTicks < 1 || $eggLayTicks > 12_000) {
            throw new InvalidArgumentException('Chicken egg timer is outside its supported bounds.');
        }
    }
    public function getEggLayTicks(): int
    {
        return $this->eggLayTicks;
    }
    public function advanceEggLayTimer(int $ticks): bool
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Chicken egg timer advance is outside its supported bound.');
        } if ($this->isBaby()) {
            return false;
        } $this->eggLayTicks = max(0, $this->eggLayTicks - $ticks);
        $this->markChanged();
        return $this->eggLayTicks === 0;
    }
    public function resetEggLayTimer(int $ticks): void
    {
        if ($ticks < 6_000 || $ticks > 12_000) {
            throw new InvalidArgumentException('Chicken egg timer is outside its supported bounds.');
        } $this->eggLayTicks = $ticks;
        $this->markChanged();
    }
    public function getController(): BreedableAnimalController
    {
        $controller = parent::getController();
        if (!$controller instanceof BreedableAnimalController) {
            throw new LogicException('A chicken must expose a breedable-animal controller.');
        } return $controller;
    }
    public function persistenceVariant(): null
    {
        return null;
    }
    public function persistenceSchemaVersion(): int
    {
        return 1;
    }
    public function persistenceData(): string
    {
        return json_encode([...$this->breedablePersistenceData(), 'eggLayTicks' => $this->eggLayTicks], JSON_THROW_ON_ERROR);
    }
    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 256) {
            throw new InvalidArgumentException('Persisted chicken state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted chicken state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['baby', 'babyGrowthTicks', 'breedingCooldownTicks', 'eggLayTicks', 'loveTicks'] || !is_int($decoded['eggLayTicks']) || $decoded['eggLayTicks'] < 0 || $decoded['eggLayTicks'] > 12_000) {
            throw new InvalidArgumentException('Persisted chicken state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->eggLayTicks = $decoded['eggLayTicks'];
    }
    protected function createController(?PluginActionBuffer $actions): BreedableAnimalController
    {
        return new BufferedBreedableAnimalController($actions, $this, $this->equipmentState());
    }
    public function maximumDownwardVelocity(): float
    {
        return 0.08;
    }
}
