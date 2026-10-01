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

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\BoggedEntity;
use Bedriox\Server\Entity\Vanilla\HuskEntity;
use Bedriox\Server\Entity\Vanilla\ZombieVillagerEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HostileFamilyScaffoldTest extends TestCase
{
    public function testHuskSubmersionProgressIsBoundedAndDurable(): void
    {
        $husk = new HuskEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0), baby: true);
        self::assertFalse($husk->advanceSubmergedConversion(20));

        $restored = new HuskEntity(EntityUuid::random(), 2, 'world', new Position(0.0, 64.0, 0.0));
        $restored->restorePersistenceState($husk->persistenceVariant(), $husk->persistenceSchemaVersion(), $husk->persistenceData());
        self::assertSame(20, $restored->getSubmergedConversionTicks());
        self::assertTrue($restored->isBaby());

        for ($elapsed = 20; $elapsed < 600; $elapsed += 20) {
            $complete = $restored->advanceSubmergedConversion(20);
        }
        self::assertTrue($complete);
        $restored->resetSubmergedConversion();
        self::assertSame(0, $restored->getSubmergedConversionTicks());
    }

    public function testZombieVillagerStateRoundTripsExactly(): void
    {
        $villager = new ZombieVillagerEntity(
            EntityUuid::random(),
            3,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
            profession: 11,
            biomeVariant: 5,
            cureTicks: 4_200,
        );
        $restored = new ZombieVillagerEntity(EntityUuid::random(), 4, 'world', new Position(0.0, 64.0, 0.0));
        $restored->restorePersistenceState(
            $villager->persistenceVariant(),
            $villager->persistenceSchemaVersion(),
            $villager->persistenceData(),
        );

        self::assertSame(11, $restored->getProfession());
        self::assertTrue($restored->isBaby());
        self::assertSame(5, $restored->getBiomeVariant());
        self::assertSame(4_200, $restored->getCureTicks());
    }

    public function testBoggedShearedStateRoundTripsAndRejectsMalformedState(): void
    {
        $bogged = new BoggedEntity(EntityUuid::random(), 5, 'world', new Position(0.0, 64.0, 0.0));
        $bogged->setSheared(true);
        $restored = new BoggedEntity(EntityUuid::random(), 6, 'world', new Position(0.0, 64.0, 0.0));
        $restored->restorePersistenceState($bogged->persistenceVariant(), $bogged->persistenceSchemaVersion(), $bogged->persistenceData());
        self::assertTrue($restored->isSheared());

        $this->expectException(InvalidArgumentException::class);
        $restored->restorePersistenceState(null, 1, '{"sheared":1}');
    }
}
