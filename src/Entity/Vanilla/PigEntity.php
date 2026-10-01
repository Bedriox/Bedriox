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

use Bedriox\Api\Entity\Controller\PigController;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Vanilla\Pig;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Plugin\BufferedPigController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class PigEntity extends BreedableAnimalEntity implements Pig, IntrinsicEntityPersistence
{
    private const float RIDER_SEAT_Y = 0.63;

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
        private bool $saddled = false,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::pig(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::pig(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
    }
    public function isSaddled(): bool
    {
        return $this->saddled;
    }
    public function setSaddled(bool $saddled): void
    {
        if ($this->saddled !== $saddled) {
            $this->saddled = $saddled;
            $this->markPresentationChanged();
        }
    }

    public function mountedPassengerOffsetY(MountSeat $seat, float $passengerHeight, bool $playerPassenger): float
    {
        return parent::mountedPassengerOffsetY($seat, $passengerHeight, $playerPassenger) + self::RIDER_SEAT_Y;
    }
    public function getController(): PigController
    {
        $controller = parent::getController();
        if (!$controller instanceof PigController) {
            throw new LogicException('A pig must expose a pig controller.');
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
        return json_encode([...$this->breedablePersistenceData(), 'saddled' => $this->saddled], JSON_THROW_ON_ERROR);
    }
    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 256) {
            throw new InvalidArgumentException('Persisted pig state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted pig state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['baby', 'babyGrowthTicks', 'breedingCooldownTicks', 'loveTicks', 'saddled'] || !is_bool($decoded['saddled'])) {
            throw new InvalidArgumentException('Persisted pig state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->saddled = $decoded['saddled'];
    }
    protected function createController(?PluginActionBuffer $actions): PigController
    {
        return new BufferedPigController($actions, $this, $this->equipmentState());
    }
}
