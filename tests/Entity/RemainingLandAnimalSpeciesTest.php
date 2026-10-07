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

use Bedriox\Api\Entity\Capability\FreezeImmune;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\Value\ArmadilloState;
use Bedriox\Api\Entity\Value\FoxVariant;
use Bedriox\Api\Entity\Value\MooshroomStewEffect;
use Bedriox\Api\Entity\Value\MooshroomVariant;
use Bedriox\Api\Entity\Value\PandaActivity;
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
        $fox->beginPounce();
        self::assertTrue($fox->isPouncing());
        self::assertFalse($fox->isFaceplanted());
        $fox->beginFaceplant();
        self::assertFalse($fox->isPouncing());
        self::assertTrue($fox->isFaceplanted());
        $fox->advanceHuntingState(20);
        self::assertTrue($fox->isFaceplanted());
        $fox->advanceHuntingState(20);
        self::assertFalse($fox->isFaceplanted());

        $defenseTarget = EntityUuid::random();
        $fox->defendTrustedPlayerAgainst($defenseTarget, 20);
        self::assertSame($defenseTarget, $fox->getTrustedDefenseTargetUniqueId());
        $fox->advanceTrustedDefense(20);
        self::assertNull($fox->getTrustedDefenseTargetUniqueId());

        $goat = new GoatEntity(EntityUuid::random(), 12, 'world', $position, screaming: true);
        self::assertTrue($goat->isScreaming());
        $goat->setHorns(false, true);
        self::assertFalse($goat->hasLeftHorn());
        self::assertTrue($goat->hasRightHorn());
        $goat->setRamming(true);
        self::assertTrue($goat->isRamming());

        $panda = new PandaEntity(EntityUuid::random(), 13, 'world', $position);
        $panda->setGenes(PandaGene::BROWN, PandaGene::WEAK);
        self::assertSame(PandaGene::BROWN, $panda->getMainGene());
        self::assertSame(PandaGene::WEAK, $panda->getHiddenGene());
        self::assertSame(PandaGene::NORMAL, $panda->getExpressedGene());
        $panda->setGenes(PandaGene::BROWN, PandaGene::BROWN);
        $panda->setActivity(PandaActivity::ROLLING);
        self::assertSame(PandaGene::BROWN, $panda->getExpressedGene());
        $panda->setGenes(PandaGene::WEAK, PandaGene::WEAK);
        self::assertSame(10.0, $panda->getMaximumHealth());
        self::assertSame(10.0, $panda->getHealth());
        $panda->beginEating(20);
        self::assertSame(PandaActivity::EATING, $panda->getActivity());
        $panda->advanceTraitActivity(false, 20, 40);
        self::assertSame(PandaActivity::EATING, $panda->getActivity());

        $armadillo = new ArmadilloEntity(EntityUuid::random(), 14, 'world', $position);
        $armadillo->setState(ArmadilloState::ROLLED_UP_PEEKING);
        self::assertSame(ArmadilloState::ROLLED_UP_PEEKING, $armadillo->getState());
        $armadillo->resetScuteShedTimer(40);
        self::assertFalse($armadillo->advanceScuteShedTimer(20));
        self::assertTrue($armadillo->advanceScuteShedTimer(20));

        $mooshroom = new MooshroomEntity(EntityUuid::random(), 15, 'world', $position);
        $mooshroom->setVariant(MooshroomVariant::BROWN);
        self::assertSame(MooshroomVariant::BROWN, $mooshroom->getVariant());
        $mooshroom->setStewEffect(MooshroomStewEffect::BLUE_ORCHID);
        self::assertSame(MooshroomStewEffect::BLUE_ORCHID, $mooshroom->takeStewEffect());
        self::assertNull($mooshroom->takeStewEffect());
        $mooshroom->struckByLightning();
        self::assertSame(MooshroomVariant::RED, $mooshroom->getVariant());

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

        $foxTrust = EntityUuid::random();
        $fox = new FoxEntity(
            EntityUuid::random(),
            21,
            'world',
            $position,
            baby: true,
            variant: FoxVariant::SNOW,
            primaryTrustedPlayerUniqueId: $foxTrust,
            sleeping: true,
        );
        $restoredFox = new FoxEntity(EntityUuid::random(), 22, 'world', $position);
        $restoredFox->restorePersistenceState($fox->persistenceVariant(), $fox->persistenceSchemaVersion(), $fox->persistenceData());
        self::assertTrue($restoredFox->isBaby());
        self::assertSame(FoxVariant::SNOW, $restoredFox->getVariant());
        self::assertTrue($restoredFox->trustsPlayer($foxTrust));
        self::assertTrue($restoredFox->isSleeping());
        self::assertFalse($restoredFox->isPouncing());
        self::assertFalse($restoredFox->isFaceplanted());
        self::assertNull($restoredFox->getTrustedDefenseTargetUniqueId());

        $goat = new GoatEntity(
            EntityUuid::random(),
            23,
            'world',
            $position,
            screaming: true,
            leftHorn: false,
            ramming: true,
            ramTicks: 100,
            ramTargetUniqueId: EntityUuid::random(),
        );
        $restoredGoat = new GoatEntity(EntityUuid::random(), 24, 'world', $position);
        $restoredGoat->restorePersistenceState($goat->persistenceVariant(), $goat->persistenceSchemaVersion(), $goat->persistenceData());
        self::assertTrue($restoredGoat->isScreaming());
        self::assertFalse($restoredGoat->hasLeftHorn());
        self::assertTrue($restoredGoat->isRamming());

        $panda = new PandaEntity(EntityUuid::random(), 25, 'world', $position, mainGene: PandaGene::PLAYFUL, hiddenGene: PandaGene::BROWN, activity: PandaActivity::SNEEZING);
        $restoredPanda = new PandaEntity(EntityUuid::random(), 26, 'world', $position);
        $restoredPanda->restorePersistenceState($panda->persistenceVariant(), $panda->persistenceSchemaVersion(), $panda->persistenceData());
        self::assertSame(PandaGene::PLAYFUL, $restoredPanda->getMainGene());
        self::assertSame(PandaGene::BROWN, $restoredPanda->getHiddenGene());
        self::assertSame(PandaActivity::SNEEZING, $restoredPanda->getActivity());

        $armadillo = new ArmadilloEntity(EntityUuid::random(), 27, 'world', $position, state: ArmadilloState::ROLLED_UP_RELAXING);
        $restoredArmadillo = new ArmadilloEntity(EntityUuid::random(), 28, 'world', $position);
        $restoredArmadillo->restorePersistenceState($armadillo->persistenceVariant(), $armadillo->persistenceSchemaVersion(), $armadillo->persistenceData());
        self::assertSame(ArmadilloState::ROLLED_UP_RELAXING, $restoredArmadillo->getState());
        self::assertSame($armadillo->getScuteShedTicks(), $restoredArmadillo->getScuteShedTicks());

        $mooshroom = new MooshroomEntity(
            EntityUuid::random(),
            29,
            'world',
            $position,
            variant: MooshroomVariant::BROWN,
            stewEffect: MooshroomStewEffect::ALLIUM,
        );
        $restoredMooshroom = new MooshroomEntity(EntityUuid::random(), 30, 'world', $position);
        $restoredMooshroom->restorePersistenceState($mooshroom->persistenceVariant(), $mooshroom->persistenceSchemaVersion(), $mooshroom->persistenceData());
        self::assertSame(MooshroomVariant::BROWN, $restoredMooshroom->getVariant());
        self::assertSame(MooshroomStewEffect::ALLIUM, $restoredMooshroom->getStewEffect());

        $sniffer = new SnifferEntity(EntityUuid::random(), 31, 'world', $position, digging: true);
        $sniffer->rememberDigSite(4, 63, -2);
        $restoredSniffer = new SnifferEntity(EntityUuid::random(), 32, 'world', $position);
        $restoredSniffer->restorePersistenceState($sniffer->persistenceVariant(), $sniffer->persistenceSchemaVersion(), $sniffer->persistenceData());
        self::assertTrue($restoredSniffer->isDigging());
        self::assertTrue($restoredSniffer->hasDugAt(4, 63, -2));

        $trustedPlayer = EntityUuid::random();
        $ocelot = new OcelotEntity(EntityUuid::random(), 33, 'world', $position, baby: true, trustedPlayerUniqueId: $trustedPlayer);
        $restoredOcelot = new OcelotEntity(EntityUuid::random(), 34, 'world', $position);
        $restoredOcelot->restorePersistenceState($ocelot->persistenceVariant(), $ocelot->persistenceSchemaVersion(), $ocelot->persistenceData());
        self::assertTrue($restoredOcelot->isBaby());
        self::assertTrue($restoredOcelot->isTrusting());
        self::assertSame($trustedPlayer, $restoredOcelot->getTrustedPlayerUniqueId());

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

    public function testMooshroomStewStateRejectsInvalidSpeciesAndPersistence(): void
    {
        foreach ([
            'minecraft:poppy' => 0,
            'minecraft:cornflower' => 1,
            'minecraft:pink_tulip' => 2,
            'minecraft:azure_bluet' => 3,
            'minecraft:lily_of_the_valley' => 4,
            'minecraft:dandelion' => 5,
            'minecraft:blue_orchid' => 6,
            'minecraft:allium' => 7,
            'minecraft:oxeye_daisy' => 8,
            'minecraft:wither_rose' => 9,
            'minecraft:torchflower' => 10,
            'minecraft:open_eyeblossom' => 11,
            'minecraft:closed_eyeblossom' => 12,
        ] as $flower => $damage) {
            self::assertSame($damage, MooshroomStewEffect::fromFlower($flower)?->value);
        }
        self::assertNull(MooshroomStewEffect::fromFlower('minecraft:stone'));

        $red = new MooshroomEntity(EntityUuid::random(), 50, 'world', new Position(0.0, 64.0, 0.0));
        try {
            $red->setStewEffect(MooshroomStewEffect::LILY_OF_THE_VALLEY);
            self::fail('A red mooshroom accepted a suspicious-stew effect.');
        } catch (InvalidArgumentException) {
            self::assertNull($red->getStewEffect());
        }

        $this->expectException(InvalidArgumentException::class);
        $red->restorePersistenceState(
            MooshroomVariant::RED->value,
            1,
            str_replace('"stewEffect":null', '"stewEffect":4', $red->persistenceData()),
        );
    }

    public function testSnifferDigSiteMemoryIsBoundedUniqueAndRejectsMalformedPersistence(): void
    {
        $sniffer = new SnifferEntity(EntityUuid::random(), 51, 'world', new Position(0.0, 64.0, 0.0));
        for ($x = 0; $x < 21; ++$x) {
            $sniffer->rememberDigSite($x, 63, 0);
        }
        $sniffer->rememberDigSite(20, 63, 0);

        self::assertSame(20, $sniffer->getRememberedDigSiteCount());
        self::assertFalse($sniffer->hasDugAt(0, 63, 0));
        self::assertTrue($sniffer->hasDugAt(20, 63, 0));

        $this->expectException(InvalidArgumentException::class);
        $sniffer->restorePersistenceState(
            null,
            1,
            str_replace('"rememberedDigSites":"1,63,0;', '"rememberedDigSites":"bad;', $sniffer->persistenceData()),
        );
    }

    public function testSnifferDigCompletionIsExactOnceAndInvalidGroundInterruptsIt(): void
    {
        $sniffer = new SnifferEntity(EntityUuid::random(), 52, 'world', new Position(0.0, 64.0, 0.0));
        self::assertFalse($sniffer->advanceSniffing(20, true));
        self::assertTrue($sniffer->isDigging());
        self::assertFalse($sniffer->advanceSniffing(20, false));
        self::assertFalse($sniffer->isDigging());

        for ($ticks = 0; $ticks < 10; ++$ticks) {
            self::assertFalse($sniffer->advanceSniffing(20, true));
        }
        self::assertTrue($sniffer->isDigging());
        for ($ticks = 0; $ticks < 5; ++$ticks) {
            self::assertFalse($sniffer->advanceSniffing(20, true));
        }
        self::assertTrue($sniffer->advanceSniffing(20, true));
        self::assertFalse($sniffer->advanceSniffing(20, true));
    }

    public function testGoatHornLossAndRamCompletionAreBoundedAndExactOnce(): void
    {
        $target = EntityUuid::random();
        $goat = new GoatEntity(EntityUuid::random(), 53, 'world', new Position(0.0, 64.0, 0.0));
        $goat->beginRam($target);
        self::assertTrue($goat->isRamming());
        self::assertSame($target, $goat->getRamTargetUniqueId());
        self::assertTrue($goat->loseHorn());
        self::assertFalse($goat->hasLeftHorn());
        self::assertTrue($goat->loseHorn());
        self::assertFalse($goat->hasRightHorn());
        self::assertFalse($goat->loseHorn());

        $goat->finishRam();
        self::assertFalse($goat->isRamming());
        self::assertNull($goat->getRamTargetUniqueId());
        self::assertFalse($goat->canBeginRam());
    }

    public function testGoatReducesOnlyFallDamageWithoutProducingNegativeDamage(): void
    {
        $goat = new GoatEntity(EntityUuid::random(), 54, 'world', new Position(0.0, 64.0, 0.0));

        self::assertSame(0.0, $goat->modifyIncomingDamage(10.0, EntityDamageCause::FALL));
        self::assertSame(2.0, $goat->modifyIncomingDamage(12.0, EntityDamageCause::FALL));
        self::assertSame(12.0, $goat->modifyIncomingDamage(12.0, EntityDamageCause::ATTACK));
    }

    public function testPowderSnowFreezingIsBoundedAndPolarBearsRemainImmune(): void
    {
        $goat = new GoatEntity(EntityUuid::random(), 55, 'world', new Position(0.0, 64.0, 0.0));
        for ($tick = 1; $tick < 140; ++$tick) {
            self::assertFalse($goat->advanceFreezingState(true, true, $tick));
        }
        self::assertTrue($goat->advanceFreezingState(true, true, 160));
        self::assertSame(140, $goat->getFreezeTicks());
        self::assertSame(1.0, $goat->freezingEffectStrength());
        self::assertTrue($goat->advanceFreezingState(true, true, 200));

        for ($tick = 201; $tick <= 270; ++$tick) {
            $goat->advanceFreezingState(false, true, $tick);
        }
        self::assertSame(0, $goat->getFreezeTicks());

        $polarBear = new PolarBearEntity(EntityUuid::random(), 56, 'world', new Position(0.0, 64.0, 0.0));
        self::assertInstanceOf(FreezeImmune::class, $polarBear);
        for ($tick = 1; $tick <= 200; ++$tick) {
            self::assertFalse($polarBear->advanceFreezingState(true, false, $tick));
        }
        self::assertSame(0, $polarBear->getFreezeTicks());
    }
}
