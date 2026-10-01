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

namespace Bedriox\Server\Tests\Entity\Loot;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityIdentifier;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Server\Entity\Loot\BoggedLootTable;
use Bedriox\Server\Entity\Loot\LootContext;
use Bedriox\Server\Entity\Loot\LootRandomSource;
use Bedriox\Server\Entity\Loot\ParchedLootTable;
use Bedriox\Server\Entity\Loot\StrayLootTable;
use Bedriox\Server\Entity\Loot\WitherSkeletonLootTable;
use PHPUnit\Framework\TestCase;

final class HostileFamilyLootTableTest extends TestCase
{
    public function testSkeletonVariantsAddTheirExactSpecialDropsForPlayerKills(): void
    {
        $player = self::player();

        $stray = (new StrayLootTable())->roll(self::context('minecraft:stray', $player), new FamilyLootRandom([0, 0, 1]));
        self::assertSame(19, $stray[0]->auxValue);

        $bogged = (new BoggedLootTable())->roll(self::context('minecraft:bogged', $player), new FamilyLootRandom([0, 0, 1]));
        self::assertSame(26, $bogged[0]->auxValue);

        $parched = (new ParchedLootTable())->roll(self::context('minecraft:parched', $player), new FamilyLootRandom([0, 0, 2]));
        self::assertSame(35, $parched[0]->auxValue);
        self::assertSame(2, $parched[0]->count);
    }

    public function testWitherSkeletonDropsCoalBonesAndPlayerHeadChance(): void
    {
        $drops = (new WitherSkeletonLootTable())->roll(
            self::context('minecraft:wither_skeleton', self::player()),
            new FamilyLootRandom([1, 2, 24]),
        );

        self::assertSame(
            ['minecraft:coal', 'minecraft:bone', 'minecraft:wither_skeleton_skull'],
            array_column($drops, 'identifier'),
        );
        self::assertSame([1, 2, 1], array_column($drops, 'count'));
    }

    private static function context(string $identifier, ?Player $killer): LootContext
    {
        return new LootContext(
            new VanillaEntityIdentifier($identifier),
            null,
            $killer,
            false,
            [],
            SpawnCause::NATURAL,
            2,
        );
    }

    private static function player(): Player
    {
        return new Player(
            'Player',
            '00000000-0000-4000-8000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory([], 0),
        );
    }
}

final class FamilyLootRandom implements LootRandomSource
{
    private int $offset = 0;

    /** @param list<int> $values */
    public function __construct(private readonly array $values) {}

    public function nextInt(int $minimum, int $maximum): int
    {
        $value = $this->values[$this->offset++] ?? throw new \RuntimeException('Random script is exhausted.');
        if ($value < $minimum || $value > $maximum) {
            throw new \RuntimeException('Random script value is outside the requested range.');
        }

        return $value;
    }
}
