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

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Value\VillagerActivity;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Value\VillagerType;
use Bedriox\Api\Entity\Value\VillagerWorkstation;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\Vanilla\VillagerEntity;
use Bedriox\Server\Entity\Vanilla\ZombieVillagerEntity;
use Bedriox\Server\Gameplay\Trading\DefaultVillagerTradeCatalog;
use Bedriox\Server\Gameplay\Trading\VillagerTradeCodec;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VillagerDomainTest extends TestCase
{
    public function testCurrentBedrockProfessionAndTypeValuesRemainStable(): void
    {
        self::assertSame(0, VillagerProfession::UNEMPLOYED->value);
        self::assertSame(1, VillagerProfession::FARMER->value);
        self::assertSame(13, VillagerProfession::MASON->value);
        self::assertSame(14, VillagerProfession::NITWIT->value);
        self::assertSame(VillagerProfession::LIBRARIAN, VillagerProfession::fromName('librarian'));

        self::assertSame(0, VillagerType::PLAINS->value);
        self::assertSame(6, VillagerType::TAIGA->value);
        self::assertSame(VillagerType::SNOW, VillagerType::fromName('snow'));
    }

    public function testEveryWorkingProfessionHasOneUniqueWorkstation(): void
    {
        $workstations = [];
        foreach (VillagerProfession::cases() as $profession) {
            $workstation = VillagerWorkstation::forProfession($profession);
            if (!$profession->canTrade()) {
                self::assertNull($workstation);
                continue;
            }
            self::assertNotNull($workstation);
            self::assertSame($profession, $workstation->profession());
            self::assertArrayNotHasKey($workstation->value, $workstations);
            $workstations[$workstation->value] = true;
        }
        self::assertCount(13, $workstations);
    }

    public function testDefaultOffersAreDeterministicBoundedAndCoverEveryLevel(): void
    {
        $catalog = new DefaultVillagerTradeCatalog();
        foreach (VillagerProfession::cases() as $profession) {
            $first = $catalog->generate($profession, 42);
            self::assertEquals($first, $catalog->generate($profession, 42));
            if (!$profession->canTrade()) {
                self::assertSame([], $first->offers);
                continue;
            }
            self::assertLessThanOrEqual(VillagerTradeList::MAXIMUM_OFFERS, count($first->offers));
            self::assertGreaterThanOrEqual(2, count(array_filter(
                $first->offers,
                static fn(VillagerTradeOffer $offer): bool => $offer->level === VillagerLevel::NOVICE,
            )));
            self::assertEqualsCanonicalizing(
                VillagerLevel::cases(),
                array_values(array_unique(array_map(static fn(VillagerTradeOffer $offer): VillagerLevel => $offer->level, $first->offers), SORT_REGULAR)),
            );
        }
    }

    public function testDefaultTradeExperienceAdvancesEachTierWithinItsOfferCapacity(): void
    {
        $offers = (new DefaultVillagerTradeCatalog())->generate(VillagerProfession::ARMORER, 42)->offers;
        $experienceByLevel = [];
        foreach ($offers as $offer) {
            $experienceByLevel[$offer->level->value] = $offer->villagerExperience;
        }

        self::assertSame([
            VillagerLevel::NOVICE->value => 1,
            VillagerLevel::APPRENTICE->value => 5,
            VillagerLevel::JOURNEYMAN->value => 10,
            VillagerLevel::EXPERT->value => 15,
            VillagerLevel::MASTER->value => 20,
        ], $experienceByLevel);
    }

    public function testTradeCodecPreservesInputsEconomyAndGeneration(): void
    {
        $offer = new VillagerTradeOffer(
            'bedriox:test_trade',
            VillagerLevel::JOURNEYMAN,
            new ItemStack('minecraft:emerald', 4),
            new ItemStack('minecraft:book', 1),
            new ItemStack('minecraft:enchanted_book', 1),
            2,
            12,
            10,
            true,
            0.2,
            3,
            -1,
        );
        $trades = new VillagerTradeList([$offer], 7);

        self::assertEquals($trades, VillagerTradeCodec::decode(VillagerTradeCodec::encode($trades)));
        self::assertSame(3, $offer->withCompletedUse()->uses);
        self::assertSame(7, $offer->withCompletedUses(5)->uses);
        $this->expectException(InvalidArgumentException::class);
        $offer->withCompletedUses(11);
    }

    public function testOriginalFiveOfferCatalogueExpandsWithoutLosingTradeUsage(): void
    {
        $catalog = new DefaultVillagerTradeCatalog();
        $legacy = new VillagerTradeList([
            new VillagerTradeOffer(
                'bedriox:shepherd/wool',
                VillagerLevel::NOVICE,
                new ItemStack('minecraft:white_wool', 18),
                null,
                new ItemStack('minecraft:emerald', 1),
                uses: 3,
                demand: 2,
            ),
            new VillagerTradeOffer(
                'bedriox:shepherd/shears',
                VillagerLevel::APPRENTICE,
                new ItemStack('minecraft:emerald', 2),
                null,
                new ItemStack('minecraft:shears', 1),
            ),
            new VillagerTradeOffer(
                'bedriox:shepherd/dye',
                VillagerLevel::JOURNEYMAN,
                new ItemStack('minecraft:white_dye', 12),
                null,
                new ItemStack('minecraft:emerald', 1),
            ),
            new VillagerTradeOffer(
                'bedriox:shepherd/banner',
                VillagerLevel::EXPERT,
                new ItemStack('minecraft:emerald', 3),
                null,
                new ItemStack('minecraft:white_banner', 1),
            ),
            new VillagerTradeOffer(
                'bedriox:shepherd/painting',
                VillagerLevel::MASTER,
                new ItemStack('minecraft:emerald', 2),
                null,
                new ItemStack('minecraft:painting', 1),
            ),
        ], 7);

        $upgraded = $catalog->upgradePersisted(VillagerProfession::SHEPHERD, 42, $legacy);

        self::assertGreaterThan(count($legacy->offers), count($upgraded->offers));
        self::assertSame(8, $upgraded->generation);
        self::assertSame(VillagerLevel::NOVICE, $upgraded->offer('bedriox:shepherd/shears')?->level);
        $wool = $upgraded->offer('bedriox:shepherd/wool');
        self::assertNotNull($wool);
        self::assertSame(3, $wool->uses);
        self::assertSame(2, $wool->demand);
    }

    public function testSpawnVariantAndPersistenceRetainProfessionTypeAndOffers(): void
    {
        $entity = new VillagerEntity(
            '00000000-0000-4000-8000-000000000001',
            10,
            'world',
            new Position(1.5, 64.0, 2.5),
        );
        $entity->applySpawnVariant('librarian:desert');
        $entity->replaceTrades((new DefaultVillagerTradeCatalog())->generate(VillagerProfession::LIBRARIAN, 7));
        $entity->setTradeProgress(VillagerLevel::JOURNEYMAN, 75);
        $entity->assignWorkstation(new BlockPosition(2, 64, 2));
        $entity->recordRestock(12);

        $restored = new VillagerEntity(
            '00000000-0000-4000-8000-000000000001',
            11,
            'world',
            new Position(1.5, 64.0, 2.5),
        );
        $restored->restorePersistenceState(
            $entity->persistenceVariant(),
            $entity->persistenceSchemaVersion(),
            $entity->persistenceData(),
        );

        self::assertSame(VillagerProfession::LIBRARIAN, $restored->getProfession());
        self::assertSame(VillagerType::DESERT, $restored->getVillagerType());
        self::assertSame(VillagerLevel::JOURNEYMAN, $restored->getVillagerLevel());
        self::assertSame(75, $restored->getVillagerExperience());
        self::assertEquals($entity->getTrades(), $restored->getTrades());
        self::assertSame(1, $restored->getRestocksToday());
        self::assertEquals(new BlockPosition(2, 64, 2), $restored->getWorkstation());
    }

    public function testBedActivityAndSleepingPersist(): void
    {
        $entity = new VillagerEntity(
            '00000000-0000-4000-8000-000000000001',
            10,
            'world',
            new Position(1.5, 64.0, 2.5),
        );
        $entity->assignBed(new BlockPosition(2, 64, 2));
        $entity->setActivity(VillagerActivity::REST);
        $entity->setSleeping(true);

        $restored = new VillagerEntity(
            '00000000-0000-4000-8000-000000000001',
            11,
            'world',
            new Position(1.5, 64.0, 2.5),
        );
        $restored->restorePersistenceState(
            $entity->persistenceVariant(),
            $entity->persistenceSchemaVersion(),
            $entity->persistenceData(),
        );

        self::assertEquals(new BlockPosition(2, 64, 2), $restored->getBed());
        self::assertEquals(new \Bedriox\Api\World\BlockPosition(2, 64, 2), $restored->getBedPosition());
        self::assertSame(VillagerActivity::REST, $restored->getActivity());
        self::assertTrue($restored->isSleeping());
        self::assertTrue($restored->hasExclusiveAiActivity());
    }

    public function testZombieVillagerPreservesCompleteVillagerStateForCuring(): void
    {
        $villager = new VillagerEntity(
            '00000000-0000-4000-8000-000000000001',
            10,
            'world',
            new Position(1.5, 64.0, 2.5),
        );
        $villager->applySpawnVariant('librarian:desert');
        $villager->assignBed(new BlockPosition(2, 64, 2));
        $villager->setBaby(true);

        $zombie = new ZombieVillagerEntity(
            '00000000-0000-4000-8000-000000000002',
            11,
            'world',
            new Position(1.5, 64.0, 2.5),
        );
        $zombie->preserveVillagerState($villager);

        self::assertSame(VillagerProfession::LIBRARIAN->value, $zombie->getProfession());
        self::assertSame(VillagerType::DESERT->value, $zombie->getBiomeVariant());
        self::assertTrue($zombie->isBaby());
        self::assertNotNull($zombie->getVillagerState());

        $restored = new ZombieVillagerEntity(
            '00000000-0000-4000-8000-000000000002',
            12,
            'world',
            new Position(1.5, 64.0, 2.5),
        );
        $restored->restorePersistenceState(
            $zombie->persistenceVariant(),
            $zombie->persistenceSchemaVersion(),
            $zombie->persistenceData(),
        );
        self::assertSame($zombie->getVillagerState(), $restored->getVillagerState());
    }

    public function testRestockingIsWorkstationBoundAndLimitedToTwicePerDay(): void
    {
        $offer = new VillagerTradeOffer(
            'bedriox:test_trade',
            VillagerLevel::NOVICE,
            new ItemStack('minecraft:emerald', 1),
            null,
            new ItemStack('minecraft:bread', 1),
            uses: 4,
        );
        $entity = new VillagerEntity(
            '00000000-0000-4000-8000-000000000001',
            10,
            'world',
            new Position(1.5, 64.0, 2.5),
            profession: VillagerProfession::FARMER,
            trades: new VillagerTradeList([$offer], 2),
        );

        self::assertFalse($entity->restock(8));
        $entity->assignWorkstation(new BlockPosition(2, 64, 2));
        self::assertTrue($entity->restock(8));
        self::assertSame(0, $entity->getTrades()->offers[0]->uses);
        self::assertSame(3, $entity->getTrades()->generation);
        self::markFirstTradeUsed($entity);
        self::assertTrue($entity->restock(8));
        self::markFirstTradeUsed($entity);
        self::assertFalse($entity->restock(8));
        self::assertTrue($entity->restock(9));
    }

    public function testVillagerLevelThresholdsAreCentralized(): void
    {
        self::assertSame(VillagerLevel::NOVICE, VillagerLevel::forExperience(0));
        self::assertSame(VillagerLevel::APPRENTICE, VillagerLevel::forExperience(10));
        self::assertSame(VillagerLevel::JOURNEYMAN, VillagerLevel::forExperience(70));
        self::assertSame(VillagerLevel::EXPERT, VillagerLevel::forExperience(150));
        self::assertSame(VillagerLevel::MASTER, VillagerLevel::forExperience(250));
    }

    public function testAssigningProfessionSelectsStableDefaultOffers(): void
    {
        $entity = new VillagerEntity(
            '00000000-0000-4000-8000-000000000001',
            10,
            'world',
            new Position(1.5, 64.0, 2.5),
        );

        $entity->setProfession(VillagerProfession::FARMER);

        self::assertNotSame([], $entity->getTrades()->offers);
        self::assertSame(1, $entity->getTrades()->generation);
        self::assertSame(VillagerLevel::NOVICE, $entity->getVillagerLevel());
        self::assertSame(0, $entity->getVillagerExperience());
    }

    public function testMalformedSpawnVariantFailsBeforePublication(): void
    {
        $entity = new VillagerEntity(
            '00000000-0000-4000-8000-000000000001',
            10,
            'world',
            new Position(1.5, 64.0, 2.5),
        );

        $this->expectException(InvalidArgumentException::class);
        $entity->applySpawnVariant('librarian:unknown');
    }

    private static function markFirstTradeUsed(VillagerEntity $entity): void
    {
        $trades = $entity->getTrades();
        $offers = $trades->offers;
        $offers[0] = $offers[0]->withCompletedUse();
        $entity->replaceTrades(new VillagerTradeList($offers, $trades->generation + 1));
    }
}
