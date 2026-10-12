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

namespace Bedriox\Server\Tests\Gameplay\Block;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\BlockBreakEffect;
use Bedriox\Server\Gameplay\Block\BlockBreakRules;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Block\BlockDropKind;
use Bedriox\Server\Gameplay\Block\BlockDropRules;
use Bedriox\Server\Gameplay\Block\BlockType;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VanillaBlockLootRegistryTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, int}> */
    public static function oreDrops(): iterable
    {
        yield 'emerald' => ['minecraft:emerald_ore', 'minecraft:iron_pickaxe', 'minecraft:emerald', 1];
        yield 'deepslate emerald' => ['minecraft:deepslate_emerald_ore', 'minecraft:iron_pickaxe', 'minecraft:emerald', 1];
        yield 'lapis' => ['minecraft:lapis_ore', 'minecraft:stone_pickaxe', 'minecraft:lapis_lazuli', 4];
        yield 'deepslate lapis' => ['minecraft:deepslate_lapis_ore', 'minecraft:stone_pickaxe', 'minecraft:lapis_lazuli', 4];
        yield 'deepslate coal' => ['minecraft:deepslate_coal_ore', 'minecraft:wooden_pickaxe', 'minecraft:coal', 1];
        yield 'deepslate copper' => ['minecraft:deepslate_copper_ore', 'minecraft:stone_pickaxe', 'minecraft:raw_copper', 2];
        yield 'deepslate iron' => ['minecraft:deepslate_iron_ore', 'minecraft:stone_pickaxe', 'minecraft:raw_iron', 1];
        yield 'deepslate gold' => ['minecraft:deepslate_gold_ore', 'minecraft:iron_pickaxe', 'minecraft:raw_gold', 1];
        yield 'deepslate redstone' => ['minecraft:deepslate_redstone_ore', 'minecraft:iron_pickaxe', 'minecraft:redstone', 4];
        yield 'deepslate diamond' => ['minecraft:deepslate_diamond_ore', 'minecraft:iron_pickaxe', 'minecraft:diamond', 1];
        yield 'nether quartz' => ['minecraft:quartz_ore', 'minecraft:wooden_pickaxe', 'minecraft:quartz', 1];
        yield 'nether gold' => ['minecraft:nether_gold_ore', 'minecraft:wooden_pickaxe', 'minecraft:gold_nugget', 2];
    }

    #[DataProvider('oreDrops')]
    public function testCurrentOreFamiliesUseRegisteredLoot(
        string $blockIdentifier,
        string $toolIdentifier,
        string $dropIdentifier,
        int $minimumCount,
    ): void {
        [$blocks, $items] = self::catalogs();
        $drops = BlockDropRules::drops(
            $blocks->type($blockIdentifier),
            $items->type($toolIdentifier),
            new RegisteredLootRandom([$minimumCount]),
        );

        self::assertCount(1, $drops);
        self::assertSame($dropIdentifier, $drops[0]->identifier);
        self::assertSame($minimumCount, $drops[0]->count);
    }

    public function testOreDefinitionsEnforceTiersAndPreserveSilkTouchIdentity(): void
    {
        [$blocks, $items] = self::catalogs();
        $diamond = $blocks->type('minecraft:deepslate_diamond_ore');

        self::assertSame(ToolTier::Iron, $diamond->requiredTier);
        self::assertSame(4.5, $diamond->hardness);
        self::assertSame([], BlockDropRules::drops(
            $diamond,
            $items->type('minecraft:stone_pickaxe'),
            new RegisteredLootRandom([]),
        ));
        self::assertSame('minecraft:deepslate_diamond_ore', BlockDropRules::drops(
            $diamond,
            $items->type('minecraft:iron_pickaxe'),
            new RegisteredLootRandom([]),
            silkTouch: true,
        )[0]->identifier);
    }

    public function testAncientDebrisRequiresDiamondTierAndDropsItself(): void
    {
        [$blocks, $items] = self::catalogs();
        $debris = $blocks->type('minecraft:ancient_debris');

        self::assertSame(30.0, $debris->hardness);
        self::assertSame(ToolTier::Diamond, $debris->requiredTier);
        self::assertFalse(BlockBreakRules::canHarvest($debris, $items->type('minecraft:iron_pickaxe')));
        self::assertSame('minecraft:ancient_debris', BlockDropRules::drops(
            $debris,
            $items->type('minecraft:diamond_pickaxe'),
            new RegisteredLootRandom([]),
        )[0]->identifier);
    }

    public function testSoilBlocksUseVanillaNormalAndSilkDrops(): void
    {
        [$blocks] = self::catalogs();
        foreach (['minecraft:grass_block', 'minecraft:mycelium', 'minecraft:podzol'] as $identifier) {
            self::assertSame('minecraft:dirt', BlockDropRules::drops(
                $blocks->type($identifier),
                null,
                new RegisteredLootRandom([]),
            )[0]->identifier);
            self::assertSame($identifier, BlockDropRules::drops(
                $blocks->type($identifier),
                null,
                new RegisteredLootRandom([]),
                silkTouch: true,
            )[0]->identifier);
        }
    }

    public function testInfestedBlocksDropNothingAndRequestSilverfishUnlessSilkTouched(): void
    {
        [$blocks, $items] = self::catalogs();
        $infested = $blocks->type('minecraft:infested_stone');
        $pickaxe = $items->type('minecraft:wooden_pickaxe');

        self::assertSame([], BlockDropRules::drops($infested, $pickaxe, new RegisteredLootRandom([])));
        self::assertSame(BlockBreakEffect::SpawnSilverfish, BlockDropRules::breakEffect($infested));
        self::assertSame('minecraft:stone', BlockDropRules::drops(
            $infested,
            $pickaxe,
            new RegisteredLootRandom([]),
            silkTouch: true,
        )[0]->identifier);
        self::assertSame(BlockBreakEffect::None, BlockDropRules::breakEffect($infested, silkTouch: true));
    }

    public function testCreakingHeartDropsResinAndIsRecoverableWithSilkTouch(): void
    {
        [$blocks] = self::catalogs();
        $heart = $blocks->type('minecraft:creaking_heart');

        $drops = BlockDropRules::drops($heart, null, new RegisteredLootRandom([3, 2]), fortuneLevel: 1);
        self::assertSame('minecraft:resin_clump', $drops[0]->identifier);
        self::assertSame(6, $drops[0]->count);
        self::assertSame('minecraft:creaking_heart', BlockDropRules::drops(
            $heart,
            null,
            new RegisteredLootRandom([]),
            silkTouch: true,
        )[0]->identifier);
    }

    public function testOnlyNaturalCreakingHeartsAwardExperienceEvenWithSilkTouch(): void
    {
        [$blocks] = self::catalogs();
        $placedHeart = $blocks->type('minecraft:creaking_heart');
        $naturalHeart = new BlockType(
            CanonicalBlockState::from('minecraft:creaking_heart', [
                'creaking_heart_state' => 'dormant',
                'natural' => 1,
                'pillar_axis' => 'y',
            ]),
            $placedHeart->hardness,
            $placedHeart->preferredTool,
            $placedHeart->requiredTier,
            BlockDropKind::Self,
        );

        self::assertSame(0, BlockDropRules::experience(
            $placedHeart,
            null,
            new RegisteredLootRandom([]),
        ));
        self::assertSame(24, BlockDropRules::experience(
            $naturalHeart,
            null,
            new RegisteredLootRandom([24]),
            silkTouch: true,
        ));
    }

    public function testOreExperienceIsSuppressedBySilkTouchAndIncompatibleTools(): void
    {
        [$blocks, $items] = self::catalogs();
        $emerald = $blocks->type('minecraft:emerald_ore');

        self::assertSame(7, BlockDropRules::experience(
            $emerald,
            $items->type('minecraft:iron_pickaxe'),
            new RegisteredLootRandom([7]),
        ));
        self::assertSame(0, BlockDropRules::experience(
            $emerald,
            $items->type('minecraft:stone_pickaxe'),
            new RegisteredLootRandom([]),
        ));
        self::assertSame(0, BlockDropRules::experience(
            $emerald,
            $items->type('minecraft:iron_pickaxe'),
            new RegisteredLootRandom([]),
            silkTouch: true,
        ));
    }

    public function testDoubleSlabDropsTwoMatchingSlabs(): void
    {
        [$blocks] = self::catalogs();
        $drops = BlockDropRules::drops(
            $blocks->type('minecraft:oak_double_slab'),
            null,
            new RegisteredLootRandom([]),
        );

        self::assertCount(1, $drops);
        self::assertSame('minecraft:oak_slab', $drops[0]->identifier);
        self::assertSame(2, $drops[0]->count);
    }

    /** @return array{BlockCatalog, ItemCatalog} */
    private static function catalogs(): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());

        return [
            BlockCatalog::vanilla($states, $data->blockItemMappingRegistry()),
            ItemCatalog::vanilla($data->itemNetworkRegistry()),
        ];
    }
}

/** @internal */
final class RegisteredLootRandom implements DropRandom
{
    /** @param list<int> $values */
    public function __construct(private array $values) {}

    public function integer(int $minimum, int $maximum): int
    {
        $value = array_shift($this->values);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('The deterministic loot random sequence is missing or outside its requested range.');
        }

        return $value;
    }
}
