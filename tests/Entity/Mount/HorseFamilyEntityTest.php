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

namespace Bedriox\Server\Tests\Entity\Mount;

use Bedriox\Api\Entity\Capability\Rideable;
use Bedriox\Api\Entity\Controller\MountController;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Vanilla\Horse;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Mount\HorseFamilyEntity;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HorseFamilyEntityTest extends TestCase
{
    public function testBoundedMountStateAndSeatGeometryAreShared(): void
    {
        $owner = EntityUuid::random();
        $mount = self::mount(ownerUniqueId: $owner, saddled: true, temper: 42, seatCapacity: 2);

        self::assertInstanceOf(Horse::class, $mount);
        self::assertInstanceOf(Rideable::class, $mount);
        self::assertSame($owner, $mount->getOwnerUniqueId());
        self::assertTrue($mount->isTamed());
        self::assertTrue($mount->isSaddled());
        self::assertSame(42, $mount->getTemper());
        self::assertSame(2, $mount->getSeatCapacity());
        self::assertEqualsWithDelta(
            1.25,
            $mount->mountedPassengerOffsetY(MountSeat::DRIVER, 1.6, true),
            0.000_000_001,
        );
    }

    public function testControllerAppliesBoundedMountMutations(): void
    {
        $mount = self::mount();
        $controller = $mount->getController();

        self::assertInstanceOf(MountController::class, $controller);
        $controller->setTemper(100);
        $controller->setSaddled(true);
        self::assertSame(100, $mount->getTemper());
        self::assertTrue($mount->isSaddled());

        $this->expectException(InvalidArgumentException::class);
        $controller->setTemper(101);
    }

    public function testDurableFamilyStateRoundTripsThroughFocusedPayload(): void
    {
        $owner = EntityUuid::random();
        $mount = self::mount(ownerUniqueId: $owner, saddled: true, temper: 73, baby: true);
        $restored = self::mount();

        $restored->restoreFamilyState($mount->familyState());

        self::assertSame($owner, $restored->getOwnerUniqueId());
        self::assertTrue($restored->isSaddled());
        self::assertSame(73, $restored->getTemper());
        self::assertTrue($restored->isBaby());
    }

    private static function mount(
        ?string $ownerUniqueId = null,
        bool $saddled = false,
        int $temper = 0,
        bool $baby = false,
        int $seatCapacity = 1,
    ): TestHorseEntity {
        return new TestHorseEntity(
            EntityUuid::random(),
            random_int(1, PHP_INT_MAX),
            'world',
            new Position(0.0, 64.0, 0.0),
            ownerUniqueId: $ownerUniqueId,
            saddled: $saddled,
            temper: $temper,
            baby: $baby,
            seatCapacity: $seatCapacity,
        );
    }
}

final class TestHorseEntity extends HorseFamilyEntity implements Horse
{
    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        ?string $ownerUniqueId = null,
        bool $saddled = false,
        int $temper = 0,
        bool $baby = false,
        int $seatCapacity = 1,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::cow(),
            $worldName,
            $position,
            VanillaAiBehaviors::cow(),
            baby: $baby,
            ownerUniqueId: $ownerUniqueId,
            saddled: $saddled,
            temper: $temper,
            seatCapacity: $seatCapacity,
            driverSeatOffsetY: 1.25,
            passengerSeatOffsetX: 0.0,
            passengerSeatOffsetY: 1.25,
            passengerSeatOffsetZ: -0.5,
        );
    }

    /** @return array<mixed> */
    public function familyState(): array
    {
        return $this->horseFamilyPersistenceData();
    }

    /** @param array<mixed> $state */
    public function restoreFamilyState(array $state): void
    {
        $this->restoreHorseFamilyPersistenceData($state);
    }
}
