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

namespace Bedriox\Server\Entity\Mount;

use Bedriox\Api\Entity\Capability\Rideable;
use Bedriox\Api\Entity\Controller\MountController;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\TameableAnimalEntity;
use Bedriox\Server\Plugin\BufferedMountController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;

/** Shared authoritative tame, saddle, temper, seating, and breeding state for mount animals. */
abstract class HorseFamilyEntity extends TameableAnimalEntity implements Rideable
{
    public const int MAXIMUM_TEMPER = 100;

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
        bool $baby = false,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
        ?string $ownerUniqueId = null,
        private bool $saddled = false,
        private int $temper = 0,
        bool $sitting = false,
        private readonly int $seatCapacity = 1,
        private readonly float $driverSeatOffsetX = 0.0,
        private readonly float $driverSeatOffsetY = 0.0,
        private readonly float $driverSeatOffsetZ = 0.0,
        private readonly ?float $passengerSeatOffsetX = null,
        private readonly ?float $passengerSeatOffsetY = null,
        private readonly ?float $passengerSeatOffsetZ = null,
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
        if ($seatCapacity < 1 || $seatCapacity > MountRegistry::MAXIMUM_PASSENGERS_PER_VEHICLE
            || !$this->validSeatCoordinate($driverSeatOffsetX)
            || !$this->validSeatCoordinate($driverSeatOffsetY)
            || !$this->validSeatCoordinate($driverSeatOffsetZ)
            || ($seatCapacity > 1 && ($passengerSeatOffsetX === null || $passengerSeatOffsetY === null || $passengerSeatOffsetZ === null))
            || ($passengerSeatOffsetX !== null && !$this->validSeatCoordinate($passengerSeatOffsetX))
            || ($passengerSeatOffsetY !== null && !$this->validSeatCoordinate($passengerSeatOffsetY))
            || ($passengerSeatOffsetZ !== null && !$this->validSeatCoordinate($passengerSeatOffsetZ))) {
            throw new InvalidArgumentException('Mount seating state is outside its supported bounds.');
        }
        self::validateTemper($temper);
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
        $this->initializeTameableState($ownerUniqueId, $sitting);
    }

    final public function isSaddled(): bool
    {
        return $this->saddled;
    }

    final public function getTemper(): int
    {
        return $this->temper;
    }

    final public function getSeatCapacity(): int
    {
        return $this->seatCapacity;
    }

    /** @internal Authoritative controller and interaction mutation. */
    final public function setSaddled(bool $saddled): void
    {
        if ($this->saddled !== $saddled) {
            $this->saddled = $saddled;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller and interaction mutation. */
    final public function setTemper(int $temper): void
    {
        self::validateTemper($temper);
        if ($this->temper !== $temper) {
            $this->temper = $temper;
            $this->markChanged();
        }
    }

    final public function mountedPassengerOffsetY(MountSeat $seat, float $passengerHeight, bool $playerPassenger): float
    {
        return $this->mountedPassengerOffset($seat, $passengerHeight, $playerPassenger)->y;
    }

    final public function mountedPassengerOffset(MountSeat $seat, float $passengerHeight, bool $playerPassenger): MountSeatOffset
    {
        if ($seat !== MountSeat::DRIVER && $this->seatCapacity > 1) {
            return new MountSeatOffset(
                $this->passengerSeatOffsetX ?? $this->driverSeatOffsetX,
                $this->passengerSeatOffsetY ?? $this->driverSeatOffsetY,
                $this->passengerSeatOffsetZ ?? $this->driverSeatOffsetZ,
            );
        }

        return new MountSeatOffset($this->driverSeatOffsetX, $this->driverSeatOffsetY, $this->driverSeatOffsetZ);
    }

    final public function getController(): MountController
    {
        $controller = parent::getController();
        if (!$controller instanceof MountController) {
            throw new LogicException('A horse-family entity must expose a mount controller.');
        }

        return $controller;
    }

    /**
     * @return array{baby: bool, babyGrowthTicks: int, breedingCooldownTicks: int, loveTicks: int, ownerUniqueId: ?string, saddled: bool, sitting: bool, temper: int}
     */
    final protected function horseFamilyPersistenceData(): array
    {
        return [
            ...$this->breedablePersistenceData(),
            ...$this->tameablePersistenceData(),
            'saddled' => $this->saddled,
            'temper' => $this->temper,
        ];
    }

    /** @param array<mixed> $data */
    final protected function restoreHorseFamilyPersistenceData(array $data): void
    {
        foreach (['saddled', 'temper'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidArgumentException('Persisted horse-family state is missing mount data.');
            }
        }
        if (!is_bool($data['saddled']) || !is_int($data['temper'])) {
            throw new InvalidArgumentException('Persisted horse-family state is malformed.');
        }
        self::validateTemper($data['temper']);
        $this->restoreBreedablePersistenceData($data);
        $this->restoreTameablePersistenceData($data);
        $this->saddled = $data['saddled'];
        $this->temper = $data['temper'];
    }

    final protected function createController(?PluginActionBuffer $actions): MountController
    {
        return new BufferedMountController($actions, $this, $this, $this->equipmentState());
    }

    private static function validateTemper(int $temper): void
    {
        if ($temper < 0 || $temper > self::MAXIMUM_TEMPER) {
            throw new InvalidArgumentException('Mount temper is outside its supported bounds.');
        }
    }

    private function validSeatCoordinate(float $coordinate): bool
    {
        return is_finite($coordinate) && $coordinate >= -64.0 && $coordinate <= 64.0;
    }
}
