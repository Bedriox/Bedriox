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

use Bedriox\Api\Entity\Value\ArmadilloState;
use Bedriox\Api\Entity\Value\FoxVariant;
use Bedriox\Api\Entity\Value\MooshroomVariant;
use Bedriox\Api\Entity\Value\PandaGene;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\ArmadilloEntity;
use Bedriox\Server\Entity\Vanilla\FoxEntity;
use Bedriox\Server\Entity\Vanilla\GoatEntity;
use Bedriox\Server\Entity\Vanilla\MooshroomEntity;
use Bedriox\Server\Entity\Vanilla\OcelotEntity;
use Bedriox\Server\Entity\Vanilla\PandaEntity;
use Bedriox\Server\Entity\Vanilla\PolarBearEntity;
use Bedriox\Server\Entity\Vanilla\SnifferEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemainingLandAnimalSpeciesTest extends TestCase
{
    public function testSpeciesExposeCanonicalIdentityDimensionsAndHealth(): void
    {
        $position = new Position(0.0, 64.0, 0.0);
        $species = [
            [new FoxEntity(EntityUuid::random(), 1, 'world', $position), 'minecraft:fox', 0.6, 0.7, 10.0],
            [new GoatEntity(EntityUuid::random(), 2, 'world', $position), 'minecraft:goat', 0.63, 0.91, 10.0],
            [new PandaEntity(EntityUuid::random(), 3, 'world', $position), 'minecraft:panda', 1.125, 1.25, 20.0],
            [new PolarBearEntity(EntityUuid::random(), 4, 'world', $position), 'minecraft:polar_bear', 1.3, 1.4, 30.0],
            [new ArmadilloEntity(EntityUuid::random(), 5, 'world', $position), 'minecraft:armadillo', 0.7, 0.65, 12.0],
            [new MooshroomEntity(EntityUuid::random(), 6, 'world', $position), 'minecraft:mooshroom', 0.9, 1.4, 10.0],
            [new SnifferEntity(EntityUuid::random(), 7, 'world', $position), 'minecraft:sniffer', 1.9, 1.75, 14.0],
            [new OcelotEntity(EntityUuid::random(), 8, 'world', $position), 'minecraft:ocelot', 0.6, 0.7, 10.0],
        ];

        foreach ($species as [$entity, $identifier, $width, $height, $health]) {
            self::assertSame($identifier, $entity->getType()->identifier());
            self::assertSame($width, $entity->collisionWidth());
            self::assertSame($height, $entity->collisionHeight());
            self::assertSame($health, $entity->getMaximumHealth());
        }
    }

    public function testFocusedSpeciesStateIsTypedAndChangesPresentationRevision(): void
    {
        $position = new Position(0.0, 64.0, 0.0);

        $fox = new FoxEntity(EntityUuid::random(), 11, 'world', $position);
        $foxRevision = $fox->presentationRevision();
        $fox->setVariant(FoxVariant::SNOW);
        self::assertSame(FoxVariant::SNOW, $fox->getVariant());
        self::assertGreaterThan($foxRevision, $fox->presentationRevision());

        $goat = new GoatEntity(EntityUuid::random(), 12, 'world', $position, screaming: true);
        self::assertTrue($goat->isScreaming());
        $goat->setHorns(false, true);
        self::assertFalse($goat->hasLeftHorn());
        self::assertTrue($goat->hasRightHorn());

        $panda = new PandaEntity(EntityUuid::random(), 13, 'world', $position);
        $panda->setGenes(PandaGene::BROWN, PandaGene::WEAK);
        self::assertSame(PandaGene::BROWN, $panda->getMainGene());
        self::assertSame(PandaGene::WEAK, $panda->getHiddenGene());

        $armadillo = new ArmadilloEntity(EntityUuid::random(), 14, 'world', $position);
        $armadillo->setState(ArmadilloState::ROLLED_UP_PEEKING);
        self::assertSame(ArmadilloState::ROLLED_UP_PEEKING, $armadillo->getState());

        $mooshroom = new MooshroomEntity(EntityUuid::random(), 15, 'world', $position);
        $mooshroom->setVariant(MooshroomVariant::BROWN);
        self::assertSame(MooshroomVariant::BROWN, $mooshroom->getVariant());

        $sniffer = new SnifferEntity(EntityUuid::random(), 16, 'world', $position, baby: true);
        self::assertSame(0.45, $sniffer->scale());
        $sniffer->setDigging(true);
        self::assertTrue($sniffer->isDigging());

        $polarBear = new PolarBearEntity(EntityUuid::random(), 17, 'world', $position, baby: true);
        self::assertTrue($polarBear->isBaby());
        self::assertSame(0.65, $polarBear->collisionWidth());
    }

    public function testFocusedSpeciesStateRoundTripsThroughIntrinsicPersistence(): void
    {
        $position = new Position(0.0, 64.0, 0.0);

        $fox = new FoxEntity(EntityUuid::random(), 21, 'world', $position, baby: true, variant: FoxVariant::SNOW);
        $restoredFox = new FoxEntity(EntityUuid::random(), 22, 'world', $position);
        $restoredFox->restorePersistenceState($fox->persistenceVariant(), $fox->persistenceSchemaVersion(), $fox->persistenceData());
        self::assertTrue($restoredFox->isBaby());
        self::assertSame(FoxVariant::SNOW, $restoredFox->getVariant());

        $goat = new GoatEntity(EntityUuid::random(), 23, 'world', $position, screaming: true, leftHorn: false);
        $restoredGoat = new GoatEntity(EntityUuid::random(), 24, 'world', $position);
        $restoredGoat->restorePersistenceState($goat->persistenceVariant(), $goat->persistenceSchemaVersion(), $goat->persistenceData());
        self::assertTrue($restoredGoat->isScreaming());
        self::assertFalse($restoredGoat->hasLeftHorn());

        $panda = new PandaEntity(EntityUuid::random(), 25, 'world', $position, mainGene: PandaGene::PLAYFUL, hiddenGene: PandaGene::BROWN);
        $restoredPanda = new PandaEntity(EntityUuid::random(), 26, 'world', $position);
        $restoredPanda->restorePersistenceState($panda->persistenceVariant(), $panda->persistenceSchemaVersion(), $panda->persistenceData());
        self::assertSame(PandaGene::PLAYFUL, $restoredPanda->getMainGene());
        self::assertSame(PandaGene::BROWN, $restoredPanda->getHiddenGene());

        $armadillo = new ArmadilloEntity(EntityUuid::random(), 27, 'world', $position, state: ArmadilloState::ROLLED_UP_RELAXING);
        $restoredArmadillo = new ArmadilloEntity(EntityUuid::random(), 28, 'world', $position);
        $restoredArmadillo->restorePersistenceState($armadillo->persistenceVariant(), $armadillo->persistenceSchemaVersion(), $armadillo->persistenceData());
        self::assertSame(ArmadilloState::ROLLED_UP_RELAXING, $restoredArmadillo->getState());

        $mooshroom = new MooshroomEntity(EntityUuid::random(), 29, 'world', $position, variant: MooshroomVariant::BROWN);
        $restoredMooshroom = new MooshroomEntity(EntityUuid::random(), 30, 'world', $position);
        $restoredMooshroom->restorePersistenceState($mooshroom->persistenceVariant(), $mooshroom->persistenceSchemaVersion(), $mooshroom->persistenceData());
        self::assertSame(MooshroomVariant::BROWN, $restoredMooshroom->getVariant());

        $sniffer = new SnifferEntity(EntityUuid::random(), 31, 'world', $position, digging: true);
        $restoredSniffer = new SnifferEntity(EntityUuid::random(), 32, 'world', $position);
        $restoredSniffer->restorePersistenceState($sniffer->persistenceVariant(), $sniffer->persistenceSchemaVersion(), $sniffer->persistenceData());
        self::assertTrue($restoredSniffer->isDigging());

        $ocelot = new OcelotEntity(EntityUuid::random(), 33, 'world', $position, baby: true);
        $restoredOcelot = new OcelotEntity(EntityUuid::random(), 34, 'world', $position);
        $restoredOcelot->restorePersistenceState($ocelot->persistenceVariant(), $ocelot->persistenceSchemaVersion(), $ocelot->persistenceData());
        self::assertTrue($restoredOcelot->isBaby());

        $polarBear = new PolarBearEntity(EntityUuid::random(), 35, 'world', $position, baby: true);
        $restoredPolarBear = new PolarBearEntity(EntityUuid::random(), 36, 'world', $position);
        $restoredPolarBear->restorePersistenceState($polarBear->persistenceVariant(), $polarBear->persistenceSchemaVersion(), $polarBear->persistenceData());
        self::assertTrue($restoredPolarBear->isBaby());
    }

    public function testMalformedSpeciesPersistenceFailsClosed(): void
    {
        $fox = new FoxEntity(EntityUuid::random(), 41, 'world', new Position(0.0, 64.0, 0.0));

        $this->expectException(InvalidArgumentException::class);
        $fox->restorePersistenceState(99, 1, $fox->persistenceData());
    }
}
