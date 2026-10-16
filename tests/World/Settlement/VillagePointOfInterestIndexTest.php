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

namespace Bedriox\Server\Tests\World\Settlement;

use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Settlement\VillagePointOfInterest;
use Bedriox\Server\World\Settlement\VillagePointOfInterestIndex;
use Bedriox\Server\World\Settlement\VillagePointOfInterestType;
use PHPUnit\Framework\TestCase;

final class VillagePointOfInterestIndexTest extends TestCase
{
    public function testNearestWorkstationCanBeClaimedByOnlyOneVillager(): void
    {
        $index = new VillagePointOfInterestIndex();
        $near = new VillagePointOfInterest(
            VillagePointOfInterestType::WORKSTATION,
            new BlockPosition(2, 64, 0),
            'minecraft:lectern',
            VillagerProfession::LIBRARIAN,
        );
        $far = new VillagePointOfInterest(
            VillagePointOfInterestType::WORKSTATION,
            new BlockPosition(8, 64, 0),
            'minecraft:lectern',
            VillagerProfession::LIBRARIAN,
        );
        $index->register($far);
        $index->register($near);

        self::assertSame($near, $index->nearestAvailableWorkstation(
            new BlockPosition(0, 64, 0),
            VillagerProfession::LIBRARIAN,
            16,
        ));
        self::assertTrue($index->claim($near->position, '00000000-0000-4000-8000-000000000001'));
        self::assertSame($near, $index->workstationForVillager('00000000-0000-4000-8000-000000000001'));
        self::assertFalse($index->claim($near->position, '00000000-0000-4000-8000-000000000002'));
        self::assertSame($far, $index->nearestAvailableWorkstation(
            new BlockPosition(0, 64, 0),
            VillagerProfession::LIBRARIAN,
            16,
        ));

        $index->releaseForVillager('00000000-0000-4000-8000-000000000001');
        self::assertNull($index->claimant($near->position));
        self::assertNull($index->workstationForVillager('00000000-0000-4000-8000-000000000001'));
    }

    public function testRemovingPointAlsoDropsItsClaim(): void
    {
        $position = new BlockPosition(2, 64, 0);
        $index = new VillagePointOfInterestIndex();
        $index->register(new VillagePointOfInterest(
            VillagePointOfInterestType::WORKSTATION,
            $position,
            'minecraft:composter',
            VillagerProfession::FARMER,
        ));
        self::assertTrue($index->claim($position, '00000000-0000-4000-8000-000000000001'));

        $index->remove($position);

        self::assertNull($index->claimant($position));
        self::assertNull($index->workstationForVillager('00000000-0000-4000-8000-000000000001'));
        self::assertSame([], $index->points());
    }

    public function testClaimingAnotherPointMovesTheVillagersSingleClaim(): void
    {
        $first = new VillagePointOfInterest(
            VillagePointOfInterestType::WORKSTATION,
            new BlockPosition(2, 64, 0),
            'minecraft:lectern',
            VillagerProfession::LIBRARIAN,
        );
        $second = new VillagePointOfInterest(
            VillagePointOfInterestType::WORKSTATION,
            new BlockPosition(3, 64, 0),
            'minecraft:lectern',
            VillagerProfession::LIBRARIAN,
        );
        $villager = '00000000-0000-4000-8000-000000000001';
        $index = new VillagePointOfInterestIndex();
        $index->register($first);
        $index->register($second);

        self::assertTrue($index->claim($first->position, $villager));
        self::assertTrue($index->claim($second->position, $villager));
        self::assertNull($index->claimant($first->position));
        self::assertSame($villager, $index->claimant($second->position));
        self::assertSame($second, $index->workstationForVillager($villager));
    }

    public function testVillagerCanOwnOneBedAndOneWorkstation(): void
    {
        $villager = '00000000-0000-4000-8000-000000000001';
        $workstation = new VillagePointOfInterest(
            VillagePointOfInterestType::WORKSTATION,
            new BlockPosition(2, 64, 0),
            'minecraft:lectern',
            VillagerProfession::LIBRARIAN,
        );
        $bed = new VillagePointOfInterest(
            VillagePointOfInterestType::BED,
            new BlockPosition(4, 64, 0),
            'minecraft:bed',
        );
        $index = new VillagePointOfInterestIndex();
        $index->register($workstation);
        $index->register($bed);

        self::assertTrue($index->claim($workstation->position, $villager));
        self::assertTrue($index->claim($bed->position, $villager));
        self::assertSame($workstation, $index->workstationForVillager($villager));
        self::assertSame($bed, $index->bedForVillager($villager));

        $index->releaseForVillager($villager, VillagePointOfInterestType::BED);
        self::assertSame($workstation, $index->workstationForVillager($villager));
        self::assertNull($index->bedForVillager($villager));
    }
}
