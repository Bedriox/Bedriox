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

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\Loot\LootItemRegistry;
use Bedriox\Server\Entity\Loot\LootOutputNormalizer;
use PHPUnit\Framework\TestCase;

final class LootOutputNormalizerTest extends TestCase
{
    public function testOutputRejectsUnknownItemsAndCoalescesExactStateWithinCatalogLimits(): void
    {
        $normalizer = new LootOutputNormalizer(new NormalizerItemRegistry([
            'minecraft:egg' => 16,
            'minecraft:iron_sword' => 1,
        ]));
        $firstNbt = ItemNbt::empty()->withString('marker', 'first');
        $secondNbt = ItemNbt::empty()->withString('marker', 'second');

        $drops = $normalizer->normalize([
            new ItemStack('minecraft:egg', 10),
            new ItemStack('minecraft:unknown', 4),
            new ItemStack('minecraft:egg', 12),
            new ItemStack('minecraft:iron_sword', 1, 4, $firstNbt, 2),
            new ItemStack('minecraft:iron_sword', 1, 4, $secondNbt, 2),
        ]);

        self::assertCount(4, $drops);
        self::assertSame(['minecraft:egg', 'minecraft:egg', 'minecraft:iron_sword', 'minecraft:iron_sword'], array_column(
            $drops,
            'identifier',
        ));
        self::assertSame([16, 6, 1, 1], array_column($drops, 'count'));
        self::assertSame($firstNbt, $drops[2]->nbt);
        self::assertSame($secondNbt, $drops[3]->nbt);
    }

    public function testOutputIsCappedAtSixtyFourStacks(): void
    {
        $normalizer = new LootOutputNormalizer(new NormalizerItemRegistry(['minecraft:iron_sword' => 1]));
        $input = array_fill(0, LootOutputNormalizer::MAX_DROP_STACKS + 10, new ItemStack('minecraft:iron_sword', 1));

        $drops = $normalizer->normalize($input);

        self::assertCount(LootOutputNormalizer::MAX_DROP_STACKS, $drops);
        self::assertSame(64, array_sum(array_column($drops, 'count')));
    }
}

final readonly class NormalizerItemRegistry implements LootItemRegistry
{
    /** @param array<string, int> $maximumStackSizes */
    public function __construct(private array $maximumStackSizes) {}

    public function maximumStackSize(string $identifier): ?int
    {
        return $this->maximumStackSizes[$identifier] ?? null;
    }
}
