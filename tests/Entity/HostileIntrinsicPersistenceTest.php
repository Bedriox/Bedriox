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

use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;
use Bedriox\Server\Entity\Vanilla\MagmaCubeEntity;
use Bedriox\Server\Entity\Vanilla\SlimeEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostileIntrinsicPersistenceTest extends TestCase
{
    public function testCreeperChargedIgnitedAndFuseStateRoundTrips(): void
    {
        $source = $this->creeper(1);
        $source->setCharged(true);
        $source->setIgnited(true);
        self::assertFalse($source->advanceFuse(12));

        $restored = $this->creeper(2);
        $restored->restorePersistenceState(
            $source->persistenceVariant(),
            $source->persistenceSchemaVersion(),
            $source->persistenceData(),
        );

        self::assertTrue($restored->isCharged());
        self::assertTrue($restored->isIgnited());
        self::assertSame(12, $restored->getFuseTicks());
        self::assertTrue($restored->advanceFuse(18));
        self::assertSame(30, $restored->getFuseTicks());

        $restored->setIgnited(false);
        self::assertSame(0, $restored->getFuseTicks());
    }

    public function testRestoredProximityFuseCanStillBeCancelled(): void
    {
        $source = $this->creeper(3);
        $source->beginProximityFuse();
        self::assertFalse($source->advanceFuse(12));

        $restored = $this->creeper(4);
        $restored->restorePersistenceState(null, 1, $source->persistenceData());
        $restored->cancelProximityFuse();

        self::assertFalse($restored->isIgnited());
        self::assertSame(0, $restored->getFuseTicks());
    }

    #[DataProvider('malformedCreeperStates')]
    public function testCreeperRejectsMalformedPersistentState(string $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->creeper(5)->restorePersistenceState(null, 1, $data);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCreeperStates(): iterable
    {
        yield 'fuse exceeds duration' => ['{"charged":false,"fuseTicks":31,"ignited":true,"proximityIgnited":false}'];
        yield 'inactive fuse has progress' => ['{"charged":false,"fuseTicks":1,"ignited":false,"proximityIgnited":false}'];
        yield 'inactive proximity fuse' => ['{"charged":false,"fuseTicks":0,"ignited":false,"proximityIgnited":true}'];
        yield 'wrong scalar type' => ['{"charged":0,"fuseTicks":0,"ignited":false,"proximityIgnited":false}'];
        yield 'unknown field' => ['{"charged":false,"fuseTicks":0,"ignited":false,"proximityIgnited":false,"extra":true}'];
    }

    public function testSlimeAndMagmaCubeSizesRoundTripAsBoundedVariants(): void
    {
        $slime = new SlimeEntity(
            EntityUuid::random(),
            4,
            'world',
            new Position(0.0, 64.0, 0.0),
            SlimeSize::MEDIUM,
        );
        $slime->restorePersistenceState(
            $slime->persistenceVariant(),
            $slime->persistenceSchemaVersion(),
            $slime->persistenceData(),
        );
        self::assertSame(SlimeSize::MEDIUM, $slime->getSize());

        $magmaCube = new MagmaCubeEntity(
            EntityUuid::random(),
            5,
            'world',
            new Position(0.0, 64.0, 0.0),
            SlimeSize::SMALL,
        );
        $magmaCube->restorePersistenceState(
            $magmaCube->persistenceVariant(),
            $magmaCube->persistenceSchemaVersion(),
            $magmaCube->persistenceData(),
        );
        self::assertSame(SlimeSize::SMALL, $magmaCube->getSize());
    }

    public function testSlimeRejectsAStoredSizeThatDisagreesWithItsDefinition(): void
    {
        $slime = new SlimeEntity(
            EntityUuid::random(),
            6,
            'world',
            new Position(0.0, 64.0, 0.0),
            SlimeSize::SMALL,
        );

        $this->expectException(InvalidArgumentException::class);
        $slime->restorePersistenceState(SlimeSize::LARGE->value, 1, '{}');
    }

    private function creeper(int $runtimeId): CreeperEntity
    {
        return new CreeperEntity(
            EntityUuid::random(),
            $runtimeId,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
    }
}
