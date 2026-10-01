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

use Bedriox\Api\Entity\Controller\TameableAnimalController;
use Bedriox\Api\Entity\Value\CatVariant;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Entity\Vanilla\Cat;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\TameableAnimalEntity;
use Bedriox\Server\Plugin\BufferedTameableAnimalController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class CatEntity extends TameableAnimalEntity implements Cat, IntrinsicEntityPersistence
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
        private CatVariant $variant = CatVariant::TABBY,
        private WoolColor $collarColor = WoolColor::RED,
        ?string $ownerUniqueId = null,
        bool $sitting = false,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            TameableEntityDefinitions::cat(),
            $worldName,
            $position,
            $behavior ?? LandAnimalAiBehaviors::companion('cat', ['minecraft:cod', 'minecraft:salmon'], 0.12),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
        $this->initializeTameableState($ownerUniqueId, $sitting);
    }

    public function getVariant(): CatVariant
    {
        return $this->variant;
    }

    public function getCollarColor(): WoolColor
    {
        return $this->collarColor;
    }

    public function getController(): TameableAnimalController
    {
        $controller = parent::getController();
        if (!$controller instanceof TameableAnimalController) {
            throw new LogicException('A cat must expose a tameable-animal controller.');
        }

        return $controller;
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
        return json_encode([
            ...$this->breedablePersistenceData(),
            ...$this->tameablePersistenceData(),
            'collarColor' => $this->collarColor->value,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_int($variant) || $schemaVersion !== 1 || strlen($data) > 512) {
            throw new InvalidArgumentException('Persisted cat state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted cat state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || !is_string($decoded['collarColor'] ?? null)) {
            throw new InvalidArgumentException('Persisted cat state is malformed.');
        }
        $resolvedVariant = CatVariant::tryFrom($variant);
        $color = WoolColor::tryFrom($decoded['collarColor']);
        if ($resolvedVariant === null || $color === null) {
            throw new InvalidArgumentException('Persisted cat variant or collar color is unsupported.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->restoreTameablePersistenceData($decoded);
        $this->variant = $resolvedVariant;
        $this->collarColor = $color;
    }

    protected function createController(?PluginActionBuffer $actions): TameableAnimalController
    {
        return new BufferedTameableAnimalController($actions, $this, $this->equipmentState());
    }
}
