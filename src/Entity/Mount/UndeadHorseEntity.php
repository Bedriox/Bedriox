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
use Bedriox\Api\Entity\Capability\Sittable;
use Bedriox\Api\Entity\Capability\Tameable;
use Bedriox\Api\Entity\Controller\MountController;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\AnimalEntity;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Mount\State\HorseArmorState;
use Bedriox\Server\Plugin\BufferedMountController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;

/** Shared non-breeding horse state for skeleton and zombie horses. */
abstract class UndeadHorseEntity extends AnimalEntity implements HorseArmorHolder, Rideable, Sittable, Tameable
{
    use HorseArmorState;

    private ?string $ownerUniqueId;

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
        ?string $ownerUniqueId = null,
        private bool $saddled = false,
        private int $temper = 0,
        private bool $sitting = false,
        private readonly int $seatCapacity = 1,
        private readonly float $driverSeatOffsetX = 0.0,
        private readonly float $driverSeatOffsetY = 0.0,
        private readonly float $driverSeatOffsetZ = 0.0,
        private readonly bool $intrinsicallyTamed = false,
    ) {
        parent::__construct($uniqueId, $runtimeId, $definition, $worldName, $position, $behavior, $motion, $yaw, $pitch, $health);
        if ($seatCapacity < 1 || $seatCapacity > MountRegistry::MAXIMUM_PASSENGERS_PER_VEHICLE
            || !is_finite($driverSeatOffsetX) || !is_finite($driverSeatOffsetY) || !is_finite($driverSeatOffsetZ)
            || $driverSeatOffsetX < -64.0 || $driverSeatOffsetX > 64.0
            || $driverSeatOffsetY < -64.0 || $driverSeatOffsetY > 64.0
            || $driverSeatOffsetZ < -64.0 || $driverSeatOffsetZ > 64.0) {
            throw new InvalidArgumentException('Undead-horse seating state is outside its supported bounds.');
        }
        self::validateTemper($temper);
        $this->ownerUniqueId = $ownerUniqueId === null ? null : EntityUuid::validate($ownerUniqueId);
        if ($sitting && !$this->isTamed()) {
            throw new InvalidArgumentException('A sitting undead horse requires an owner.');
        }
    }

    final public function isTamed(): bool
    {
        return $this->intrinsicallyTamed || $this->ownerUniqueId !== null;
    }
    final public function getOwnerUniqueId(): ?string
    {
        return $this->ownerUniqueId;
    }
    final public function isSaddled(): bool
    {
        return $this->saddled;
    }
    final public function getTemper(): int
    {
        return $this->temper;
    }
    final public function isSitting(): bool
    {
        return $this->sitting;
    }
    final public function getSeatCapacity(): int
    {
        return $this->seatCapacity;
    }

    /** @internal Authoritative controller mutation. */
    final public function setOwnerUniqueId(?string $ownerUniqueId): void
    {
        $ownerUniqueId = $ownerUniqueId === null ? null : EntityUuid::validate($ownerUniqueId);
        if ($this->ownerUniqueId !== $ownerUniqueId) {
            $this->ownerUniqueId = $ownerUniqueId;
            if ($ownerUniqueId === null) {
                $this->sitting = false;
            }
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setSaddled(bool $saddled): void
    {
        if ($this->saddled !== $saddled) {
            $this->saddled = $saddled;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setTemper(int $temper): void
    {
        self::validateTemper($temper);
        if ($this->temper !== $temper) {
            $this->temper = $temper;
            $this->markChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setSitting(bool $sitting): void
    {
        if ($sitting && !$this->isTamed()) {
            throw new InvalidArgumentException('An untamed undead horse cannot be ordered to sit.');
        }
        if ($this->sitting !== $sitting) {
            $this->sitting = $sitting;
            if ($sitting) {
                $motion = $this->getMotion();
                $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
            }
            $this->markPresentationChanged();
        }
    }

    final public function mountedPassengerOffsetY(MountSeat $seat, float $passengerHeight, bool $playerPassenger): float
    {
        return $this->mountedPassengerOffset($seat, $passengerHeight, $playerPassenger)->y;
    }

    final public function mountedPassengerOffset(MountSeat $seat, float $passengerHeight, bool $playerPassenger): MountSeatOffset
    {
        $passengerBaseY = parent::mountedPassengerOffsetY($seat, $passengerHeight, $playerPassenger);

        return new MountSeatOffset(
            $this->driverSeatOffsetX,
            $passengerBaseY + $this->driverSeatOffsetY,
            $this->driverSeatOffsetZ,
        );
    }

    final public function getController(): MountController
    {
        $controller = parent::getController();
        if (!$controller instanceof MountController) {
            throw new LogicException('An undead horse must expose a mount controller.');
        }
        return $controller;
    }

    /** @return array{horseArmor: ?array<string, mixed>, ownerUniqueId: ?string, saddled: bool, sitting: bool, temper: int} */
    final protected function undeadHorsePersistenceData(): array
    {
        return [
            'ownerUniqueId' => $this->ownerUniqueId,
            'horseArmor' => $this->horseArmorPersistenceData(),
            'saddled' => $this->saddled,
            'sitting' => $this->sitting,
            'temper' => $this->temper,
        ];
    }

    /** @param array<mixed> $data */
    final protected function restoreUndeadHorsePersistenceData(array $data): void
    {
        if (array_keys($data) !== ['ownerUniqueId', 'horseArmor', 'saddled', 'sitting', 'temper']
            || ($data['ownerUniqueId'] !== null && !is_string($data['ownerUniqueId']))
            || !is_bool($data['saddled']) || !is_bool($data['sitting']) || !is_int($data['temper'])) {
            throw new InvalidArgumentException('Persisted undead-horse state is malformed.');
        }
        $owner = $data['ownerUniqueId'] === null ? null : EntityUuid::validate($data['ownerUniqueId']);
        self::validateTemper($data['temper']);
        if ($data['sitting'] && !$this->intrinsicallyTamed && $owner === null) {
            throw new InvalidArgumentException('Persisted sitting undead-horse state requires an owner.');
        }
        $this->ownerUniqueId = $owner;
        $this->restoreHorseArmor($data['horseArmor']);
        $this->saddled = $data['saddled'];
        $this->sitting = $data['sitting'];
        $this->temper = $data['temper'];
        if ($this->sitting) {
            $motion = $this->getMotion();
            $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
        }
    }

    final protected function createController(?PluginActionBuffer $actions): MountController
    {
        return new BufferedMountController($actions, $this, $this, $this->equipmentState());
    }

    private static function validateTemper(int $temper): void
    {
        if ($temper < 0 || $temper > HorseFamilyEntity::MAXIMUM_TEMPER) {
            throw new InvalidArgumentException('Mount temper is outside its supported bounds.');
        }
    }
}
