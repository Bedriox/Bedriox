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

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Loot\CreeperLootTable;
use Bedriox\Server\Entity\Loot\EndermanLootTable;
use Bedriox\Server\Entity\Loot\LootContext;
use Bedriox\Server\Entity\Loot\LootRandomSource;
use Bedriox\Server\Entity\Loot\MagmaCubeLootTable;
use Bedriox\Server\Entity\Loot\SlimeLootTable;
use Bedriox\Server\Entity\Loot\SpiderLootTable;
use Bedriox\Server\Entity\Loot\WitchLootTable;
use Bedriox\Server\Entity\Vanilla\MagmaCubeEntity;
use Bedriox\Server\Entity\Vanilla\SlimeEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class SpecialHostileLootTableTest extends TestCase
{
    public function testCommonHostileDropsAreBoundedAndPlayerSpiderEyeRuleIsApplied(): void
    {
        $player = self::player();
        $spider = (new SpiderLootTable())->roll(self::context(VanillaEntityType::SPIDER, killer: $player, looting: 1), new SpecialLootRandom([3, 1]));
        self::assertSame(['minecraft:string', 'minecraft:spider_eye'], array_column($spider, 'identifier'));
        self::assertSame([3, 1], array_column($spider, 'count'));

        $creeper = (new CreeperLootTable())->roll(self::context(VanillaEntityType::CREEPER, looting: 2), new SpecialLootRandom([4]));
        self::assertSame('minecraft:gunpowder', $creeper[0]->identifier);
        self::assertSame(4, $creeper[0]->count);

        $enderman = (new EndermanLootTable())->roll(self::context(VanillaEntityType::ENDERMAN, looting: 2), new SpecialLootRandom([3]));
        self::assertSame('minecraft:ender_pearl', $enderman[0]->identifier);
        self::assertSame(3, $enderman[0]->count);
    }

    public function testSlimeDropsOnlyComeFromEligibleSizes(): void
    {
        $largeSlime = new SlimeEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 32.0, 0.0));
        self::assertSame([], (new SlimeLootTable())->roll(
            self::context(VanillaEntityType::SLIME, subject: $largeSlime),
            new SpecialLootRandom([]),
        ));

        $smallSlime = new SlimeEntity(EntityUuid::random(), 2, 'world', new Position(0.0, 32.0, 0.0), SlimeSize::SMALL);
        $slimeDrops = (new SlimeLootTable())->roll(
            self::context(VanillaEntityType::SLIME, subject: $smallSlime),
            new SpecialLootRandom([2]),
        );
        self::assertSame('minecraft:slime_ball', $slimeDrops[0]->identifier);

        $smallMagma = new MagmaCubeEntity(EntityUuid::random(), 3, 'world', new Position(0.0, 32.0, 0.0), SlimeSize::SMALL);
        self::assertSame([], (new MagmaCubeLootTable())->roll(
            self::context(VanillaEntityType::MAGMA_CUBE, subject: $smallMagma),
            new SpecialLootRandom([]),
        ));

        $largeMagma = new MagmaCubeEntity(EntityUuid::random(), 4, 'world', new Position(0.0, 32.0, 0.0));
        $magmaDrops = (new MagmaCubeLootTable())->roll(
            self::context(VanillaEntityType::MAGMA_CUBE, subject: $largeMagma, looting: 1),
            new SpecialLootRandom([2]),
        );
        self::assertSame('minecraft:magma_cream', $magmaDrops[0]->identifier);
        self::assertSame(2, $magmaDrops[0]->count);
    }

    public function testWitchUsesBoundedIndependentRolls(): void
    {
        $drops = (new WitchLootTable())->roll(
            self::context(VanillaEntityType::WITCH),
            new SpecialLootRandom([2, 0, 1, 6, 2]),
        );

        self::assertSame(['minecraft:glass_bottle', 'minecraft:sugar'], array_column($drops, 'identifier'));
        self::assertSame([1, 2], array_column($drops, 'count'));
    }

    private static function context(
        VanillaEntityType $type,
        ?Player $killer = null,
        int $looting = 0,
        ?Entity $subject = null,
    ): LootContext {
        return new LootContext($type, null, $killer, false, [], SpawnCause::NATURAL, 2, $looting, $subject);
    }

    private static function player(): Player
    {
        return new Player(
            'Player',
            '00000000-0000-4000-8000-000000000001',
            new ApiPosition(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory([], 0),
        );
    }
}

final class SpecialLootRandom implements LootRandomSource
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
