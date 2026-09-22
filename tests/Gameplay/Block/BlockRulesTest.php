<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Gameplay\Block;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Block\BlockBreakContext;
use Bedriox\Server\Gameplay\Block\BlockBreakRules;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Block\BlockDropRules;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlockRulesTest extends TestCase
{
    public function testBreakRatesMatchAuthoritativeCompatibilityAndToolEfficiency(): void
    {
        $blocks = BlockCatalog::vanilla();
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $grass = $blocks->type('minecraft:grass_block');
        $stone = $blocks->type('minecraft:stone');

        self::assertSame(3_640, BlockBreakRules::networkBreakRate($grass, null));
        self::assertSame(436, BlockBreakRules::networkBreakRate($stone, null));
        self::assertSame(2_912, BlockBreakRules::networkBreakRate($stone, $items->type('minecraft:wooden_pickaxe')));
        self::assertFalse(BlockBreakRules::canHarvest($stone, null));
        self::assertTrue(BlockBreakRules::canHarvest($stone, $items->type('minecraft:wooden_pickaxe')));
        self::assertFalse(BlockBreakRules::canHarvest($stone, $items->type('minecraft:iron_axe')));
        self::assertFalse(BlockBreakRules::canHarvest(
            $blocks->type('minecraft:diamond_ore'),
            $items->type('minecraft:stone_pickaxe'),
        ));
    }

    public function testEnvironmentModifiersAndShearsAreApplied(): void
    {
        $blocks = BlockCatalog::vanilla();
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $grass = $blocks->type('minecraft:grass_block');
        $normal = BlockBreakRules::progressPerTick($grass, null);

        self::assertEqualsWithDelta($normal / 25.0, BlockBreakRules::progressPerTick(
            $grass,
            null,
            new BlockBreakContext(airborne: true, underwater: true),
        ), 0.0000001);
        self::assertGreaterThan($normal, BlockBreakRules::progressPerTick(
            $grass,
            null,
            new BlockBreakContext(hasteLevel: 1),
        ));
        self::assertLessThan($normal, BlockBreakRules::progressPerTick(
            $grass,
            null,
            new BlockBreakContext(miningFatigueLevel: 1),
        ));
        self::assertSame(65_535, BlockBreakRules::networkBreakRate(
            $blocks->type('minecraft:oak_leaves'),
            $items->type('minecraft:shears'),
        ));
    }

    public function testDropsRequireTheRightHarvestTierAndUseStateSpecificItems(): void
    {
        $blocks = BlockCatalog::vanilla();
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $random = new SequenceDropRandom([5, 4]);

        self::assertSame('minecraft:cobblestone', BlockDropRules::drops(
            $blocks->type('minecraft:stone'),
            $items->type('minecraft:stone_pickaxe'),
            $random,
        )[0]->identifier);
        self::assertSame([], BlockDropRules::drops(
            $blocks->type('minecraft:stone'),
            $items->type('minecraft:iron_axe'),
            $random,
        ));
        self::assertSame('minecraft:cobblestone', BlockDropRules::drops(
            $blocks->type('minecraft:cobblestone'),
            $items->type('minecraft:stone_pickaxe'),
            $random,
        )[0]->identifier);
        self::assertSame('minecraft:cobbled_deepslate', BlockDropRules::drops(
            $blocks->type('minecraft:cobbled_deepslate'),
            $items->type('minecraft:stone_pickaxe'),
            $random,
        )[0]->identifier);

        self::assertSame([], BlockDropRules::drops(
            $blocks->type('minecraft:diamond_ore'),
            $items->type('minecraft:stone_pickaxe'),
            $random,
        ));
        self::assertSame('minecraft:diamond', BlockDropRules::drops(
            $blocks->type('minecraft:diamond_ore'),
            $items->type('minecraft:iron_pickaxe'),
            $random,
        )[0]->identifier);
        self::assertSame('minecraft:dirt', BlockDropRules::drops(
            $blocks->type('minecraft:grass_block'),
            null,
            $random,
        )[0]->identifier);
        self::assertSame(4, BlockDropRules::drops(
            $blocks->type('minecraft:clay'),
            null,
            $random,
        )[0]->count);
        self::assertSame(5, BlockDropRules::drops(
            $blocks->type('minecraft:copper_ore'),
            $items->type('minecraft:stone_pickaxe'),
            $random,
        )[0]->count);
        self::assertSame(4, BlockDropRules::drops(
            $blocks->type('minecraft:redstone_ore'),
            $items->type('minecraft:iron_pickaxe'),
            $random,
        )[0]->count);
    }

    public function testGravelLeavesAndIceUseExplicitRandomAndToolRules(): void
    {
        $blocks = BlockCatalog::vanilla();
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());

        self::assertSame('minecraft:flint', BlockDropRules::drops(
            $blocks->type('minecraft:gravel'),
            null,
            new SequenceDropRandom([1]),
        )[0]->identifier);
        self::assertSame([], BlockDropRules::drops(
            $blocks->type('minecraft:ice'),
            $items->type('minecraft:wooden_pickaxe'),
            new SequenceDropRandom([]),
        ));
        self::assertSame('minecraft:ice', BlockDropRules::drops(
            $blocks->type('minecraft:ice'),
            $items->type('minecraft:wooden_pickaxe'),
            new SequenceDropRandom([]),
            silkTouch: true,
        )[0]->identifier);
        self::assertSame('minecraft:oak_leaves', BlockDropRules::drops(
            $blocks->type('minecraft:oak_leaves'),
            $items->type('minecraft:shears'),
            new SequenceDropRandom([]),
        )[0]->identifier);

        $leaves = BlockDropRules::drops(
            $blocks->type('minecraft:oak_leaves'),
            null,
            new SequenceDropRandom([1, 1, 1, 2]),
        );
        self::assertSame(
            ['minecraft:oak_sapling', 'minecraft:apple', 'minecraft:stick'],
            array_map(static fn($drop): string => $drop->identifier, $leaves),
        );
        self::assertSame(2, $leaves[2]->count);
    }
}

/** @internal */
final class SequenceDropRandom implements DropRandom
{
    /** @param list<int> $values */
    public function __construct(private array $values) {}

    public function integer(int $minimum, int $maximum): int
    {
        $value = array_shift($this->values);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('Test random sequence is missing or outside the requested range.');
        }

        return $value;
    }
}
