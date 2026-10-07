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
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Loot\LootContext;
use Bedriox\Server\Entity\Loot\LootRandomSource;
use Bedriox\Server\Entity\Loot\PolarBearLootTable;
use Bedriox\Server\Entity\Vanilla\PolarBearEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PolarBearLootQualificationTest extends TestCase
{
    #[DataProvider('fishDrops')]
    public function testPolarBearDropsRawOrCookedCodAndSalmon(
        bool $burning,
        int $fishRoll,
        string $expected,
    ): void {
        $random = new PolarBearScriptedLootRandom([2, $fishRoll]);

        $drops = (new PolarBearLootTable())->roll(self::context($burning), $random);

        self::assertCount(1, $drops);
        self::assertSame($expected, $drops[0]->identifier);
        self::assertSame(2, $drops[0]->count);
        self::assertSame(2, $random->calls);
    }

    /** @return iterable<string, array{bool, int, string}> */
    public static function fishDrops(): iterable
    {
        yield 'raw cod' => [false, 2, 'minecraft:cod'];
        yield 'cooked cod' => [true, 2, 'minecraft:cooked_cod'];
        yield 'raw salmon' => [false, 1, 'minecraft:salmon'];
        yield 'cooked salmon' => [true, 1, 'minecraft:cooked_salmon'];
    }

    public function testZeroCountOmitsDropWithoutConsumingFishSelectionRandomness(): void
    {
        $random = new PolarBearScriptedLootRandom([0]);

        self::assertSame([], (new PolarBearLootTable())->roll(self::context(false), $random));
        self::assertSame(1, $random->calls);
    }

    public function testLootingExtendsOnlyTheBoundedCountRange(): void
    {
        $random = new PolarBearScriptedLootRandom([5, 4]);

        $drops = (new PolarBearLootTable())->roll(self::context(false, 3), $random);

        self::assertCount(1, $drops);
        self::assertSame('minecraft:cod', $drops[0]->identifier);
        self::assertSame(5, $drops[0]->count);
        self::assertSame([[0, 5], [1, 4]], $random->bounds);
    }

    public function testPolarBearCubDropsNothingWithoutConsumingRandomness(): void
    {
        $cub = new PolarBearEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
        );
        $random = new PolarBearScriptedLootRandom([]);

        self::assertSame([], (new PolarBearLootTable())->roll(
            self::context(false, subject: $cub),
            $random,
        ));
        self::assertSame(0, $random->calls);
    }

    private static function context(
        bool $burning,
        int $lootingLevel = 0,
        ?PolarBearEntity $subject = null,
    ): LootContext {
        return new LootContext(
            VanillaEntityType::POLAR_BEAR,
            null,
            null,
            $burning,
            [],
            SpawnCause::NATURAL,
            2,
            $lootingLevel,
            $subject,
        );
    }
}

final class PolarBearScriptedLootRandom implements LootRandomSource
{
    public int $calls = 0;

    /** @var list<array{int, int}> */
    public array $bounds = [];

    /** @param list<int> $values */
    public function __construct(private readonly array $values) {}

    public function nextInt(int $minimum, int $maximum): int
    {
        $value = $this->values[$this->calls] ?? throw new RuntimeException('Random script is exhausted.');
        $this->bounds[] = [$minimum, $maximum];
        ++$this->calls;
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException('Random script value is outside its requested range.');
        }

        return $value;
    }
}
