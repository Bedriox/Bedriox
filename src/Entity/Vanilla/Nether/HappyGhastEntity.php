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

use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Vanilla\HappyGhast;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Mount\MountSeatOffset;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class HappyGhastEntity extends BreedableAnimalEntity implements HappyGhast, IntrinsicEntityPersistence
{
    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position, ?AiBehaviorDefinition $behavior = null, EntityMotion $motion = new EntityMotion(), float $yaw = 0.0, float $pitch = 0.0, ?float $health = null, bool $baby = false, private bool $harnessed = false, int $loveTicks = 0, int $babyGrowthTicks = 0, int $breedingCooldownTicks = 0)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::happyGhast(), $worldName, $position, $behavior ?? VanillaAiBehaviors::happyGhast(), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
    }

    public function isSaddled(): bool
    {
        return $this->harnessed;
    }

    public function setHarnessed(bool $harnessed): void
    {
        if ($this->harnessed !== $harnessed) {
            $this->harnessed = $harnessed;
            $this->markPresentationChanged();
        }
    }

    public function getSeatCapacity(): int
    {
        return $this->harnessed && !$this->isBaby() ? 4 : 0;
    }

    public function mountedPassengerOffsetY(MountSeat $seat, float $passengerHeight, bool $playerPassenger): float
    {
        return parent::mountedPassengerOffsetY($seat, $passengerHeight, $playerPassenger) + 3.7;
    }

    public function mountedPassengerOffset(MountSeat $seat, float $passengerHeight, bool $playerPassenger): MountSeatOffset
    {
        [$x, $z] = match ($seat) {
            MountSeat::DRIVER => [0.0, -1.35],
            MountSeat::PASSENGER_1 => [1.35, 0.0],
            MountSeat::PASSENGER_2 => [0.0, 1.35],
            MountSeat::PASSENGER_3 => [-1.35, 0.0],
        };

        return new MountSeatOffset($x, $this->mountedPassengerOffsetY($seat, $passengerHeight, $playerPassenger), $z);
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
        return json_encode([...$this->breedablePersistenceData(), 'harnessed' => $this->harnessed], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 128) {
            throw new InvalidArgumentException('Persisted happy-ghast state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted happy-ghast state is malformed.', previous: $error);
        }
        if (!is_array($decoded)
            || array_keys($decoded) !== ['baby', 'babyGrowthTicks', 'breedingCooldownTicks', 'loveTicks', 'harnessed']
            || !is_bool($decoded['harnessed'])) {
            throw new InvalidArgumentException('Persisted happy-ghast state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->harnessed = $decoded['harnessed'];
    }
}
