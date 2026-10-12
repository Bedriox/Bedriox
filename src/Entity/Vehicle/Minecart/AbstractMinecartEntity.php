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

namespace Bedriox\Server\Entity\Vehicle\Minecart;

use Bedriox\Api\Entity\Controller\MinecartController;
use Bedriox\Api\Entity\Controller\MobController;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Vanilla\Minecart;
use Bedriox\Api\World\Rail\RailSegment;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Mount\MountSeatOffset;
use Bedriox\Server\Entity\Mount\MountViewConstraint;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Plugin\BufferedMinecartController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

abstract class AbstractMinecartEntity extends AbstractMobEntity implements IntrinsicEntityPersistence, Minecart
{
    public const float MAXIMUM_TRACK_SPEED = 0.4;
    public const float RIDER_TRACK_ACCELERATION = 0.04;

    private const float PLAYER_SEAT_Y = 1.020_01;
    private const float STRUCTURAL_DAMAGE_MULTIPLIER = 10.0;

    private float $trackSpeed = 0.0;
    private int $hurtTicks = 0;
    private int $hurtDirection = 1;
    private ?RailSegment $currentRail = null;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        Position $position,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            $definition,
            $worldName,
            $position,
            new AiBehaviorDefinition(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->setAiEnabled(false);
    }

    public function isSaddled(): bool
    {
        return true;
    }

    public function getSeatCapacity(): int
    {
        return 1;
    }

    public function mountedPassengerOffsetY(MountSeat $seat, float $passengerHeight, bool $playerPassenger): float
    {
        return self::PLAYER_SEAT_Y;
    }

    public function mountedPassengerOffset(
        MountSeat $seat,
        float $passengerHeight,
        bool $playerPassenger,
    ): MountSeatOffset {
        return new MountSeatOffset(0.0, self::PLAYER_SEAT_Y, 0.0);
    }

    public function mountedViewConstraint(): MountViewConstraint
    {
        return MountViewConstraint::standard();
    }

    public function getTrackSpeed(): float
    {
        return $this->trackSpeed;
    }

    public function getCurrentRail(): ?RailSegment
    {
        return $this->currentRail;
    }

    /** @internal Updated by authoritative rail physics. */
    public function setCurrentRail(?RailSegment $rail): void
    {
        $this->currentRail = $rail;
    }

    /** @internal Applied by authoritative rail physics or a buffered plugin controller. */
    public function setTrackSpeed(float $trackSpeed): void
    {
        if (!is_finite($trackSpeed) || abs($trackSpeed) > self::MAXIMUM_TRACK_SPEED) {
            throw new InvalidArgumentException('Minecart track speed must be finite and within its supported bounds.');
        }
        if ($this->trackSpeed !== $trackSpeed) {
            $this->trackSpeed = $trackSpeed;
            $this->markChanged();
        }
    }

    /** @internal */
    public function stopTrackMotion(): void
    {
        $this->setTrackSpeed(0.0);
        $this->applyMotion(new EntityMotion());
    }

    /** @internal */
    public function reverseTrackMotion(): void
    {
        $this->setTrackSpeed(-$this->trackSpeed);
        $motion = $this->getMotion();
        $this->applyMotion(new EntityMotion(-$motion->x, $motion->y, -$motion->z));
    }

    public function structuralDamage(float $attackDamage, bool $instant): float
    {
        return $instant ? $this->getHealth() : $attackDamage * self::STRUCTURAL_DAMAGE_MULTIPLIER;
    }

    public function showDamageAnimation(): void
    {
        $this->hurtTicks = 9;
        $this->hurtDirection *= -1;
        $this->markPresentationChanged();
    }

    public function advanceDamageAnimation(): void
    {
        if ($this->hurtTicks > 0) {
            --$this->hurtTicks;
            $this->markPresentationChanged();
        }
    }

    public function hurtTicks(): int
    {
        return $this->hurtTicks;
    }

    public function hurtDirection(): int
    {
        return $this->hurtDirection;
    }

    public function getController(): MinecartController
    {
        $controller = parent::getController();
        if (!$controller instanceof MinecartController) {
            throw new LogicException('A minecart must expose a minecart controller.');
        }

        return $controller;
    }

    protected function createController(?PluginActionBuffer $actions): MobController
    {
        return new BufferedMinecartController($actions, $this, $this->equipmentState());
    }

    public function persistenceVariant(): null
    {
        return null;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    final public function persistenceData(): string
    {
        return json_encode([
            'trackSpeed' => $this->trackSpeed,
            'state' => $this->persistenceState(),
        ], JSON_THROW_ON_ERROR);
    }

    final public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1
            || strlen($data) > EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES) {
            throw new InvalidArgumentException('Persisted minecart state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted minecart state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['trackSpeed', 'state']
            || (!is_float($decoded['trackSpeed']) && !is_int($decoded['trackSpeed']))
            || !is_array($decoded['state'])) {
            throw new InvalidArgumentException('Persisted minecart state is malformed.');
        }
        $state = [];
        foreach ($decoded['state'] as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Persisted minecart state is malformed.');
            }
            $state[$key] = $value;
        }
        $this->setTrackSpeed((float) $decoded['trackSpeed']);
        $this->restorePersistenceData($state);
    }

    /** @return array<string, mixed> */
    protected function persistenceState(): array
    {
        return [];
    }

    /** @param array<string, mixed> $state */
    protected function restorePersistenceData(array $state): void
    {
        if ($state !== []) {
            throw new InvalidArgumentException('Persisted minecart state is malformed.');
        }
    }
}
