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

use Bedriox\Api\Entity\Capability\Ageable;
use Bedriox\Api\Entity\Capability\Breedable;
use Bedriox\Api\Entity\Capability\RangedMob;
use Bedriox\Api\Entity\Capability\Shearable;
use Bedriox\Api\Entity\Capability\Undead;
use Bedriox\Api\Entity\Controller\SheepController;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Entity\Vanilla\Sheep;
use Bedriox\Api\Entity\Vanilla\Skeleton;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LandMobFoundationTest extends TestCase
{
    public function testSheepAndSkeletonDefinitionsUseExactVanillaIdentityAndPhysicalValues(): void
    {
        $sheep = VanillaEntityDefinitions::sheep();
        self::assertSame(VanillaEntityType::SHEEP, $sheep->type);
        self::assertSame(EntityCategory::ANIMAL, $sheep->category);
        self::assertSame([0.9, 1.3, 8.0, false], [$sheep->width, $sheep->height, $sheep->maximumHealth, $sheep->burnsInDaylight]);

        $skeleton = VanillaEntityDefinitions::skeleton();
        self::assertSame(VanillaEntityType::SKELETON, $skeleton->type);
        self::assertSame(EntityCategory::MONSTER, $skeleton->category);
        self::assertSame([0.6, 1.99, 20.0, true], [$skeleton->width, $skeleton->height, $skeleton->maximumHealth, $skeleton->burnsInDaylight]);

        $sheepFactory = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::SHEEP)->factory;
        self::assertInstanceOf(SheepEntity::class, $sheepFactory(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
        ));
        $skeletonFactory = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::SKELETON)->factory;
        self::assertInstanceOf(SkeletonEntity::class, $skeletonFactory(
            EntityUuid::random(),
            2,
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
        ));
    }

    public function testSpeciesExposeExactCapabilitiesAndBoundedState(): void
    {
        $sheep = $this->sheep();
        self::assertInstanceOf(Sheep::class, $sheep);
        self::assertInstanceOf(Ageable::class, $sheep);
        self::assertInstanceOf(Breedable::class, $sheep);
        self::assertInstanceOf(Shearable::class, $sheep);
        self::assertInstanceOf(SheepController::class, $sheep->getController());
        self::assertFalse($sheep->isBaby());
        self::assertFalse($sheep->isSheared());
        self::assertSame(0, $sheep->getLoveTicks());
        self::assertSame(WoolColor::WHITE, $sheep->getWoolColor());

        $skeleton = new SkeletonEntity(EntityUuid::random(), 12, 'world', new Position(0.0, 64.0, 0.0));
        self::assertInstanceOf(Skeleton::class, $skeleton);
        self::assertInstanceOf(RangedMob::class, $skeleton);
        self::assertInstanceOf(Undead::class, $skeleton);
        self::assertSame(20.0, $skeleton->getHealth());
    }

    public function testSheepControllerAppliesImmediateBoundedSpeciesMutations(): void
    {
        $sheep = $this->sheep();
        $controller = $sheep->getController();

        $controller->setWoolColor(WoolColor::BLUE);
        $controller->setSheared(true);
        $controller->setSheared(false);
        $controller->setLoveTicks(600);

        self::assertSame(WoolColor::BLUE, $sheep->getWoolColor());
        self::assertFalse($sheep->isSheared());
        self::assertSame(600, $sheep->getLoveTicks());

        $this->expectException(InvalidArgumentException::class);
        $controller->setLoveTicks(601);
    }

    public function testSheepIntrinsicStateRoundTripsAndRejectsInvalidState(): void
    {
        $source = $this->sheep();
        $source->setWoolColor(WoolColor::RED);
        $source->setSheared(true);
        $source->setLoveTicks(120);
        $restored = $this->sheep(13);
        $restored->restorePersistenceState(
            $source->persistenceVariant(),
            $source->persistenceSchemaVersion(),
            $source->persistenceData(),
        );

        self::assertSame(WoolColor::RED, $restored->getWoolColor());
        self::assertTrue($restored->isSheared());
        self::assertSame(120, $restored->getLoveTicks());

        $this->expectException(InvalidArgumentException::class);
        $restored->restorePersistenceState('red', 1, '{"baby":true,"loveTicks":0,"sheared":true}');
    }

    public function testSheepSpeciesTimersReachTheirExactBoundaries(): void
    {
        $adult = $this->sheep();
        $adult->setLoveTicks(2);
        self::assertTrue($adult->isReadyToBreed());

        $adult->advanceSpeciesState();
        self::assertSame(1, $adult->getLoveTicks());
        self::assertTrue($adult->isReadyToBreed());

        $adult->advanceSpeciesState();
        self::assertSame(0, $adult->getLoveTicks());
        self::assertFalse($adult->isReadyToBreed());

        $adult->beginBreedingCooldown();
        for ($ticks = 0; $ticks < SheepEntity::BREEDING_COOLDOWN_TICKS - 20; $ticks += 20) {
            $adult->advanceSpeciesState(20);
        }
        $adult->setLoveTicks(SheepEntity::MAXIMUM_LOVE_TICKS);
        self::assertFalse($adult->isReadyToBreed());

        $adult->advanceSpeciesState(20);
        self::assertTrue($adult->isReadyToBreed());
        self::assertSame(SheepEntity::MAXIMUM_LOVE_TICKS - 20, $adult->getLoveTicks());

        $baby = new SheepEntity(
            EntityUuid::random(),
            14,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
        );
        $baby->accelerateGrowth(SheepEntity::BABY_GROWTH_TICKS - 1);
        self::assertTrue($baby->isBaby());
        $baby->advanceSpeciesState();
        self::assertFalse($baby->isBaby());
    }

    public function testSheepPersistenceRetainsPartiallyElapsedTimers(): void
    {
        $source = new SheepEntity(
            EntityUuid::random(),
            15,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
        );
        $source->advanceSpeciesState(20);

        $restored = $this->sheep(16);
        $restored->restorePersistenceState(
            $source->persistenceVariant(),
            $source->persistenceSchemaVersion(),
            $source->persistenceData(),
        );

        self::assertTrue($restored->isBaby());
        self::assertSame(
            [
                'baby' => true,
                'babyGrowthTicks' => SheepEntity::BABY_GROWTH_TICKS - 20,
                'breedingCooldownTicks' => 0,
                'loveTicks' => 0,
                'leashHolderUniqueId' => null,
                'leashHolderType' => null,
                'sheared' => false,
            ],
            json_decode($restored->persistenceData(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    private function sheep(int $runtimeId = 11): SheepEntity
    {
        return new SheepEntity(EntityUuid::random(), $runtimeId, 'world', new Position(0.0, 64.0, 0.0));
    }
}
