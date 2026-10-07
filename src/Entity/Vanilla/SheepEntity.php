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

use Bedriox\Api\Entity\Controller\SheepController;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Entity\Vanilla\Sheep;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Plugin\BufferedSheepController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class SheepEntity extends BreedableAnimalEntity implements Sheep, IntrinsicEntityPersistence
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
        private WoolColor $woolColor = WoolColor::WHITE,
        private bool $sheared = false,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::sheep(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::sheep(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
        if ($baby && $sheared) {
            throw new InvalidArgumentException('A baby sheep cannot be sheared.');
        }
    }

    public function isSheared(): bool
    {
        return $this->sheared;
    }

    public function getWoolColor(): WoolColor
    {
        return $this->woolColor;
    }

    public function getController(): SheepController
    {
        $controller = parent::getController();
        if (!$controller instanceof SheepController) {
            throw new LogicException('A sheep must expose a sheep controller.');
        }

        return $controller;
    }

    /** @internal Authoritative species-state mutation. */
    public function setSheared(bool $sheared): void
    {
        if ($this->isBaby() && $sheared) {
            throw new InvalidArgumentException('A baby sheep cannot be sheared.');
        }
        if ($this->sheared !== $sheared) {
            $this->sheared = $sheared;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative species-state mutation. */
    public function setWoolColor(WoolColor $color): void
    {
        if ($this->woolColor !== $color) {
            $this->woolColor = $color;
            $this->markPresentationChanged();
        }
    }

    public function persistenceVariant(): string
    {
        return $this->woolColor->value;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    public function persistenceData(): string
    {
        return json_encode([
            ...$this->breedablePersistenceData(),
            'sheared' => $this->sheared,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_string($variant) || $schemaVersion !== 1 || strlen($data) > 256) {
            throw new InvalidArgumentException('Persisted sheep state has an unsupported schema.');
        }
        $color = WoolColor::tryFrom($variant);
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted sheep state is malformed.', previous: $error);
        }
        if ($color === null || !is_array($decoded)
            || array_keys($decoded) !== array_keys([...$this->breedablePersistenceData(), 'sheared' => $this->sheared])
            || !is_bool($decoded['baby']) || !is_int($decoded['babyGrowthTicks'])
            || !is_int($decoded['breedingCooldownTicks']) || !is_int($decoded['loveTicks'])
            || !is_bool($decoded['sheared'])) {
            throw new InvalidArgumentException('Persisted sheep state is malformed.');
        }
        if ($decoded['baby'] && $decoded['sheared']) {
            throw new InvalidArgumentException('Persisted sheep state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->sheared = $decoded['sheared'];
        $this->woolColor = $color;
    }

    protected function createController(?PluginActionBuffer $actions): SheepController
    {
        return new BufferedSheepController($actions, $this, $this->equipmentState());
    }

}
