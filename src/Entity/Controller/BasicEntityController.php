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

namespace Bedriox\Server\Entity\Controller;

use Bedriox\Api\Entity\Controller\EntityController;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;

/** Internal controller for non-living entities which do not own effects, health, or equipment. */
final readonly class BasicEntityController implements EntityController
{
    public function __construct(private AbstractEntity $entity) {}

    public function isAvailable(): bool
    {
        return !$this->entity->isRemoved();
    }

    public function teleport(ApiPosition $position, ?string $worldName = null): void
    {
        $this->assertAvailable();
        $this->entity->requestControllerTransform(
            $worldName ?? $this->entity->getWorldName(),
            self::position($position),
            $this->entity->getYaw(),
            $this->entity->getPitch(),
        );
    }

    public function setRotation(float $yaw, float $pitch): void
    {
        $this->assertAvailable();
        $this->entity->requestControllerTransform(
            $this->entity->getWorldName(),
            $this->entity->internalPosition(),
            $yaw,
            $pitch,
        );
    }

    public function setMotion(Motion $motion): void
    {
        $this->assertAvailable();
        $this->entity->applyMotion(new EntityMotion($motion->x, $motion->y, $motion->z));
    }

    public function setNameTag(string $nameTag): void
    {
        $this->assertAvailable();
        $this->entity->applyNameTag($nameTag);
    }

    public function setNameTagVisible(bool $visible): void
    {
        $this->assertAvailable();
        $this->entity->applyNameTagVisible($visible);
    }

    public function setImmobile(bool $immobile): void
    {
        $this->assertAvailable();
        $this->entity->applyImmobile($immobile);
    }

    public function setInvisible(bool $invisible): void
    {
        $this->assertAvailable();
        $this->entity->applyInvisible($invisible);
    }

    public function setScale(float $scale): void
    {
        $this->assertAvailable();
        $this->entity->applyScale($scale);
    }

    public function setGravityEnabled(bool $enabled): void
    {
        $this->assertAvailable();
        $this->entity->applyGravityEnabled($enabled);
    }

    public function setOnFire(int $durationTicks, EntityCombustionCause $cause = EntityCombustionCause::PLUGIN): void
    {
        throw new LogicException('This non-living entity cannot burn.');
    }

    public function extinguish(): void
    {
        $this->assertAvailable();
    }

    public function mount(Entity $vehicle, MountSeat $seat = MountSeat::DRIVER): void
    {
        $this->assertAvailable();
        $this->entity->requestControllerMount($vehicle, $seat);
    }

    public function dismount(): void
    {
        $this->assertAvailable();
        $this->entity->requestControllerDismount();
    }

    public function despawn(): void
    {
        $this->assertAvailable();
        $this->entity->requestControllerDespawn();
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new LogicException('Entity controller is no longer available.');
        }
    }

    private static function position(ApiPosition $position): Position
    {
        foreach ([$position->x, $position->y, $position->z] as $coordinate) {
            if (!is_finite($coordinate) || abs($coordinate) > 30_000_000.0) {
                throw new InvalidArgumentException('Entity target position must be finite and bounded.');
            }
        }

        return new Position($position->x, $position->y, $position->z);
    }
}
