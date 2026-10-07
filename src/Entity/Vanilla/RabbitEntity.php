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

use Bedriox\Api\Entity\Controller\RabbitController;
use Bedriox\Api\Entity\Value\RabbitVariant;
use Bedriox\Api\Entity\Vanilla\Rabbit;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Plugin\BufferedRabbitController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class RabbitEntity extends BreedableAnimalEntity implements Rabbit, IntrinsicEntityPersistence
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
        private RabbitVariant $variant = RabbitVariant::BROWN,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::rabbit(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::rabbit(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
    }
    public function getVariant(): RabbitVariant
    {
        return $this->variant;
    }
    public function setVariant(RabbitVariant $variant): void
    {
        if ($this->variant !== $variant) {
            $this->variant = $variant;
            $this->markPresentationChanged();
        }
    }
    public function getController(): RabbitController
    {
        $controller = parent::getController();
        if (!$controller instanceof RabbitController) {
            throw new LogicException('A rabbit must expose a rabbit controller.');
        } return $controller;
    }
    public function persistenceVariant(): int
    {
        return $this->variant->value;
    }
    public function persistenceSchemaVersion(): int
    {
        return 1;
    }
    public function persistenceData(): string
    {
        return json_encode($this->breedablePersistenceData(), JSON_THROW_ON_ERROR);
    }
    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_int($variant) || $schemaVersion !== 1 || strlen($data) > 256 || ($rabbitVariant = RabbitVariant::tryFrom($variant)) === null) {
            throw new InvalidArgumentException('Persisted rabbit state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted rabbit state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== array_keys($this->breedablePersistenceData())) {
            throw new InvalidArgumentException('Persisted rabbit state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->variant = $rabbitVariant;
    }
    protected function createController(?PluginActionBuffer $actions): RabbitController
    {
        return new BufferedRabbitController($actions, $this, $this->equipmentState());
    }

    protected function visualSizeMultiplier(): float
    {
        return $this->isBaby() ? 0.4 : 0.6;
    }
}
