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

use Bedriox\Api\Entity\Capability\Angerable;
use Bedriox\Api\Entity\Controller\WolfController;
use Bedriox\Api\Entity\Value\WolfVariant;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Entity\Vanilla\Wolf;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Concern\AngerStateTrait;
use Bedriox\Server\Entity\Concern\MutableAngerState;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\TameableAnimalEntity;
use Bedriox\Server\Plugin\BufferedWolfController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class WolfEntity extends TameableAnimalEntity implements Wolf, Angerable, MutableAngerState, IntrinsicEntityPersistence
{
    use AngerStateTrait;

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
        private WolfVariant $variant = WolfVariant::PALE,
        private WoolColor $collarColor = WoolColor::RED,
        ?string $ownerUniqueId = null,
        bool $sitting = false,
        ?string $angerTargetUniqueId = null,
        int $angerTicks = 0,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            TameableEntityDefinitions::wolf(),
            $worldName,
            $position,
            $behavior ?? LandAnimalAiBehaviors::wolf(),
            $motion,
            $yaw,
            $pitch,
            $health ?? ($ownerUniqueId === null ? 8.0 : 40.0),
        );
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
        $this->initializeTameableState($ownerUniqueId, $sitting);
        $this->initializeAngerState($angerTargetUniqueId, $angerTicks);
    }

    public function getVariant(): WolfVariant
    {
        return $this->variant;
    }

    public function getCollarColor(): WoolColor
    {
        return $this->collarColor;
    }

    public function getController(): WolfController
    {
        $controller = parent::getController();
        if (!$controller instanceof WolfController) {
            throw new LogicException('A wolf must expose a wolf controller.');
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
            ...$this->angerPersistenceData(),
            'collarColor' => $this->collarColor->value,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_int($variant) || $schemaVersion !== 1 || strlen($data) > 768) {
            throw new InvalidArgumentException('Persisted wolf state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted wolf state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || !is_string($decoded['collarColor'] ?? null)) {
            throw new InvalidArgumentException('Persisted wolf state is malformed.');
        }
        $resolvedVariant = WolfVariant::tryFrom($variant);
        $color = WoolColor::tryFrom($decoded['collarColor']);
        if ($resolvedVariant === null || $color === null) {
            throw new InvalidArgumentException('Persisted wolf variant or collar color is unsupported.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->restoreTameablePersistenceData($decoded);
        $this->restoreAngerPersistenceData($decoded);
        $this->variant = $resolvedVariant;
        $this->collarColor = $color;
    }

    protected function createController(?PluginActionBuffer $actions): WolfController
    {
        return new BufferedWolfController($actions, $this, $this->equipmentState());
    }
}
