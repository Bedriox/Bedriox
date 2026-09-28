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

namespace Bedriox\Server\Tests\Gameplay\Crafting;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Crafting\ComplexCraftingRecipeEvaluator;
use Bedriox\Server\Gameplay\Crafting\CraftingGrid;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class ComplexCraftingRecipeEvaluatorTest extends TestCase
{
    private static ComplexCraftingRecipeEvaluator $recipes;
    private static int $networkId = 0;

    public static function setUpBeforeClass(): void
    {
        $data = BedrockDataSet::bundled();
        self::$recipes = new ComplexCraftingRecipeEvaluator(
            ItemCatalog::vanilla(
                $data->itemNetworkRegistry(),
                creative: $data->creativeInventoryRegistry(),
                blockItems: $data->blockItemMappingRegistry(),
            ),
            new BlockStateRegistry($data->blockStateRegistry()->states()),
        );
    }

    public function testBannerPatternRecipeDerivesPatternAndColorFromTheGrid(): void
    {
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::BANNER_ADD_PATTERN, new CraftingGrid(3, 3, [
            self::stack('minecraft:banner', auxValue: 15), null, null,
            null, self::stack('minecraft:red_dye'), null,
            null, null, null,
        ]));

        self::assertNotNull($match);
        self::assertSame([0 => 1, 4 => 1], $match->consumptionBySlot);
        self::assertSame('minecraft:banner', $match->outputs[0]->identifier);
        self::assertSame(15, $match->outputs[0]->auxValue);
        $patterns = self::collectionTags($match->outputs[0]->nbt, 'Patterns', TagType::LIST);
        self::assertCount(1, $patterns);
        self::assertInstanceOf(Tag::class, $patterns[0]);
        $pattern = self::collectionTagValues($patterns[0], TagType::COMPOUND);
        self::assertSame('mc', self::compoundValue($pattern, 'Pattern', TagType::STRING));
        self::assertSame(1, self::compoundValue($pattern, 'Color', TagType::INT));
    }

    public function testBannerDuplicateRecipeReturnsTwoCopiesOfThePatternedBanner(): void
    {
        $patterned = self::stack('minecraft:banner', nbt: self::bannerNbt('bo', 4), auxValue: 15);
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::BANNER_DUPLICATE, new CraftingGrid(2, 2, [
            $patterned,
            self::stack('minecraft:banner', auxValue: 15),
            null,
            null,
        ]));

        self::assertNotNull($match);
        self::assertSame([0 => 1, 1 => 1], $match->consumptionBySlot);
        self::assertSame(2, $match->outputs[0]->count);
        self::assertTrue($patterned->nbt?->equals($match->outputs[0]->nbt ?? ItemNbt::empty()));
        self::assertNull(self::$recipes->match(
            ComplexCraftingRecipeEvaluator::BANNER_DUPLICATE,
            new CraftingGrid(2, 2, [$patterned, self::stack('minecraft:banner', auxValue: 14), null, null]),
        ));
    }

    public function testFireworkRecipeBuildsFlightAndExplosionDataServerSide(): void
    {
        $explosion = Tag::compound([
            'Type' => Tag::byte(1),
            'FireworkColor' => Tag::intArray([0xff0000]),
        ]);
        $starNbt = ItemNbt::empty()->withTag('FireworksItem', $explosion);
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::FIREWORK_ROCKET, new CraftingGrid(3, 3, [
            self::stack('minecraft:paper', 2),
            self::stack('minecraft:gunpowder', 2),
            self::stack('minecraft:gunpowder', 2),
            self::stack('minecraft:firework_star', 2, $starNbt),
            null, null, null, null, null,
        ]), 2);

        self::assertNotNull($match);
        self::assertSame([0 => 2, 1 => 2, 2 => 2, 3 => 2], $match->consumptionBySlot);
        self::assertSame('minecraft:firework_rocket', $match->outputs[0]->identifier);
        self::assertSame(3, $match->outputs[0]->count);
        $fireworks = self::collectionTags($match->outputs[0]->nbt, 'Fireworks', TagType::COMPOUND);
        self::assertSame(2, self::compoundValue($fireworks, 'Flight', TagType::BYTE));
        $explosions = self::compoundValue($fireworks, 'Explosions', TagType::LIST);
        self::assertIsArray($explosions);
        self::assertCount(1, $explosions);
        self::assertInstanceOf(Tag::class, $explosions[0]);
        self::assertSame(1, self::compoundValue(
            self::collectionTagValues($explosions[0], TagType::COMPOUND),
            'Type',
            TagType::BYTE,
        ));
    }

    public function testWrittenBookCloneIncrementsCopyGenerationAndReturnsTheOriginal(): void
    {
        $sourceNbt = ItemNbt::empty()
            ->withTag('generation', Tag::int(0))
            ->withString('title', 'Authority');
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::WRITTEN_BOOK_CLONE, new CraftingGrid(3, 3, [
            self::stack('minecraft:written_book', nbt: $sourceNbt),
            self::stack('minecraft:writable_book'),
            self::stack('minecraft:writable_book'),
            null, null, null, null, null, null,
        ]));

        self::assertNotNull($match);
        self::assertSame([0 => 1, 1 => 1, 2 => 1], $match->consumptionBySlot);
        self::assertCount(2, $match->outputs);
        self::assertSame(2, $match->outputs[0]->count);
        $copyNbt = self::requireNbt($match->outputs[0]->nbt);
        self::assertSame(1, $copyNbt->int('generation'));
        self::assertSame('Authority', $copyNbt->string('title'));
        self::assertSame(1, $match->outputs[1]->count);
        self::assertTrue($sourceNbt->equals($match->outputs[1]->nbt ?? ItemNbt::empty()));
    }

    public function testMapClonePreservesTheAuthoritativeMapIdentity(): void
    {
        $mapNbt = ItemNbt::empty()->withTag('map_uuid', Tag::long(73));
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::MAP_CLONE, new CraftingGrid(3, 3, [
            self::stack('minecraft:filled_map', nbt: $mapNbt, auxValue: 5),
            self::stack('minecraft:empty_map'),
            self::stack('minecraft:empty_map'),
            null, null, null, null, null, null,
        ]));

        self::assertNotNull($match);
        self::assertSame(3, $match->outputs[0]->count);
        self::assertSame(5, $match->outputs[0]->auxValue);
        self::assertTrue($mapNbt->equals($match->outputs[0]->nbt ?? ItemNbt::empty()));
        self::assertNull(self::$recipes->match(ComplexCraftingRecipeEvaluator::MAP_CLONE, new CraftingGrid(2, 2, [
            self::stack('minecraft:filled_map'),
            self::stack('minecraft:empty_map'),
            null,
            null,
        ])));
    }

    public function testDecoratedPotRecipeRecordsEachAcceptedSideInGridOrder(): void
    {
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::DECORATED_POT, new CraftingGrid(3, 3, [
            null, self::stack('minecraft:angler_pottery_sherd'), null,
            self::stack('minecraft:brick'), null, self::stack('minecraft:flow_pottery_sherd'),
            null, self::stack('minecraft:snort_pottery_sherd'), null,
        ]));

        self::assertNotNull($match);
        self::assertSame([1 => 1, 3 => 1, 5 => 1, 7 => 1], $match->consumptionBySlot);
        self::assertSame('minecraft:decorated_pot', $match->outputs[0]->identifier);
        $sherds = self::collectionTags($match->outputs[0]->nbt, 'sherds', TagType::LIST);
        self::assertSame([
            'minecraft:angler_pottery_sherd',
            'minecraft:brick',
            'minecraft:flow_pottery_sherd',
            'minecraft:snort_pottery_sherd',
        ], array_map(static fn(Tag $tag): mixed => $tag->value(), $sherds));
        self::assertNull(self::$recipes->match(ComplexCraftingRecipeEvaluator::DECORATED_POT, new CraftingGrid(3, 3, [
            null, self::stack('minecraft:made_up_pottery_sherd'), null,
            self::stack('minecraft:brick'), null, self::stack('minecraft:brick'),
            null, self::stack('minecraft:brick'), null,
        ])));
    }

    public function testMapExtendRequiresThePaperRingAndIncrementsBoundedScale(): void
    {
        $mapNbt = ItemNbt::empty()
            ->withTag('map_uuid', Tag::long(109))
            ->withTag('map_scale', Tag::int(2))
            ->withString('custom', 'preserved');
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::MAP_EXTEND, new CraftingGrid(3, 3, [
            self::stack('minecraft:paper'), self::stack('minecraft:paper'), self::stack('minecraft:paper'),
            self::stack('minecraft:paper'), self::stack('minecraft:filled_map', nbt: $mapNbt, auxValue: 5), self::stack('minecraft:paper'),
            self::stack('minecraft:paper'), self::stack('minecraft:paper'), self::stack('minecraft:paper'),
        ]));

        self::assertNotNull($match);
        self::assertSame(array_fill(0, 9, 1), $match->consumptionBySlot);
        self::assertSame('minecraft:filled_map', $match->outputs[0]->identifier);
        self::assertSame(5, $match->outputs[0]->auxValue);
        $resultNbt = self::requireNbt($match->outputs[0]->nbt);
        self::assertSame(3, $resultNbt->int('map_scale'));
        self::assertSame('preserved', $resultNbt->string('custom'));
        self::assertSame(109, self::requireTagValue($resultNbt, 'map_uuid', TagType::LONG));

        $maximumScale = $mapNbt->withTag('map_scale', Tag::int(4));
        self::assertNull(self::$recipes->match(ComplexCraftingRecipeEvaluator::MAP_EXTEND, new CraftingGrid(3, 3, [
            self::stack('minecraft:paper'), self::stack('minecraft:paper'), self::stack('minecraft:paper'),
            self::stack('minecraft:paper'), self::stack('minecraft:filled_map', nbt: $maximumScale), self::stack('minecraft:paper'),
            self::stack('minecraft:paper'), self::stack('minecraft:paper'), self::stack('minecraft:paper'),
        ])));
    }

    public function testRepairRecipeCombinesRemainingDurabilityAndDiscardsUserData(): void
    {
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::REPAIR_ITEM, new CraftingGrid(2, 2, [
            self::stack('minecraft:iron_pickaxe', nbt: ItemNbt::empty()->withString('custom', 'left'), damage: 200),
            self::stack('minecraft:iron_pickaxe', damage: 100),
            null,
            null,
        ]));

        self::assertNotNull($match);
        self::assertSame([0 => 1, 1 => 1], $match->consumptionBySlot);
        self::assertSame('minecraft:iron_pickaxe', $match->outputs[0]->identifier);
        self::assertSame(37, $match->outputs[0]->damage);
        self::assertNull($match->outputs[0]->nbt);

        $bow = self::$recipes->match(ComplexCraftingRecipeEvaluator::REPAIR_ITEM, new CraftingGrid(2, 2, [
            self::stack('minecraft:bow', damage: 300),
            self::stack('minecraft:bow', damage: 250),
            null,
            null,
        ]));
        self::assertNotNull($bow);
        self::assertSame('minecraft:bow', $bow->outputs[0]->identifier);
        self::assertSame(146, $bow->outputs[0]->damage);
    }

    public function testBannerToShieldCopiesBannerDecorationAndPreservesShieldState(): void
    {
        $shieldNbt = ItemNbt::empty()->withString('custom', 'shield');
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::BANNER_TO_SHIELD, new CraftingGrid(2, 2, [
            self::stack('minecraft:shield', nbt: $shieldNbt, damage: 12),
            self::stack('minecraft:banner', nbt: self::bannerNbt('cre', 6), auxValue: 14),
            null,
            null,
        ]));

        self::assertNotNull($match);
        self::assertSame([0 => 1, 1 => 1], $match->consumptionBySlot);
        self::assertSame(12, $match->outputs[0]->damage);
        $resultNbt = self::requireNbt($match->outputs[0]->nbt);
        self::assertSame('shield', $resultNbt->string('custom'));
        self::assertSame(14, $resultNbt->int('Base'));
        self::assertCount(1, self::collectionTags($match->outputs[0]->nbt, 'Patterns', TagType::LIST));
    }

    public function testMapUpgradeDerivesTheLocatorVariantWithoutTrustingAClaimedOutput(): void
    {
        $mapNbt = ItemNbt::empty()->withTag('map_uuid', Tag::long(91));
        $match = self::$recipes->match(ComplexCraftingRecipeEvaluator::MAP_UPGRADE, new CraftingGrid(2, 2, [
            self::stack('minecraft:filled_map', nbt: $mapNbt),
            self::stack('minecraft:compass'),
            null,
            null,
        ]));

        self::assertNotNull($match);
        self::assertSame([0 => 1, 1 => 1], $match->consumptionBySlot);
        self::assertSame(2, $match->outputs[0]->auxValue);
        self::assertTrue($mapNbt->equals($match->outputs[0]->nbt ?? ItemNbt::empty()));
        self::assertNull(self::$recipes->match(ComplexCraftingRecipeEvaluator::MAP_UPGRADE, new CraftingGrid(2, 2, [
            self::stack('minecraft:filled_map'),
            self::stack('minecraft:compass'),
            null,
            null,
        ])));
    }

    public function testCartographyOnlyRecipesNeverExecuteInTheCraftingGrid(): void
    {
        $grid = new CraftingGrid(2, 2, [
            self::stack('minecraft:filled_map'),
            self::stack('minecraft:empty_map'),
            null,
            null,
        ]);
        foreach ([
            ComplexCraftingRecipeEvaluator::CARTOGRAPHY_MAP_CLONE,
            ComplexCraftingRecipeEvaluator::CARTOGRAPHY_MAP_EXTEND,
            ComplexCraftingRecipeEvaluator::CARTOGRAPHY_MAP_LOCK,
            ComplexCraftingRecipeEvaluator::CARTOGRAPHY_MAP_UPGRADE,
        ] as $uuid) {
            self::assertNull(self::$recipes->match($uuid, $grid), $uuid);
        }
    }

    private static function stack(
        string $identifier,
        int $count = 1,
        ?ItemNbt $nbt = null,
        int $auxValue = 0,
        int $damage = 0,
    ): InventoryStack {
        return new InventoryStack(
            $identifier,
            $count,
            ++self::$networkId,
            damage: $damage,
            nbt: $nbt,
            auxValue: $auxValue,
        );
    }

    private static function bannerNbt(string $pattern, int $color): ItemNbt
    {
        return ItemNbt::empty()->withTag('Patterns', Tag::list(TagType::COMPOUND, [
            Tag::compound([
                'Pattern' => Tag::string($pattern),
                'Color' => Tag::int($color),
            ]),
        ]));
    }

    /** @return array<int|string, Tag> */
    private static function collectionTags(?ItemNbt $nbt, string $name, TagType $type): array
    {
        $nbt = self::requireNbt($nbt);
        $tag = $nbt->tag($name);
        self::assertNotNull($tag);

        return self::collectionTagValues($tag, $type);
    }

    /** @return array<int|string, Tag> */
    private static function collectionTagValues(Tag $tag, TagType $type): array
    {
        self::assertSame($type, $tag->type());
        $values = $tag->value();
        self::assertIsArray($values);

        $tags = [];
        foreach ($values as $key => $value) {
            self::assertInstanceOf(Tag::class, $value);
            $tags[$key] = $value;
        }

        return $tags;
    }

    private static function requireNbt(?ItemNbt $nbt): ItemNbt
    {
        self::assertNotNull($nbt);

        return $nbt;
    }

    private static function requireTagValue(ItemNbt $nbt, string $name, TagType $type): mixed
    {
        $tag = $nbt->tag($name);
        self::assertNotNull($tag);
        self::assertSame($type, $tag->type());

        return $tag->value();
    }

    /** @param array<int|string, Tag> $compound */
    private static function compoundValue(array $compound, string $name, TagType $type): mixed
    {
        self::assertArrayHasKey($name, $compound);
        self::assertInstanceOf(Tag::class, $compound[$name]);
        self::assertSame($type, $compound[$name]->type());

        return $compound[$name]->value();
    }
}
